<?php

namespace App\Billing\Actions;

use App\Billing\Checkout;
use App\Billing\SubscriptionBuilder;
use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send the organisation to an Airwallex Billing Checkout for a module, paid monthly or yearly. Needs billing.manage, and
 * only a root organisation pays: its sub-organisations share what it buys. It pays in the currency its country decides
 * (`preferredCurrency()`), so its country must be set. The first subscription to a module starts
 * with a free trial of `billing.trial_days`. The local subscription is written by the
 * webhook once the checkout completes, as in Cashier.
 *
 * An organisation has at most one open checkout per module: starting another cancels the one before, so two tabs can't
 * end in two paid subscriptions. A lock on the organisation keeps two quick clicks from making two Airwallex customers.
 */
final class StartSubscriptionCheckout
{
    public const array INTERVALS = ['monthly', 'yearly'];

    public function handle(User $actor, Organisation $organisation, Module $module, string $interval, string $returnUrl): Checkout
    {
        Gate::forUser($actor)->authorize(Ability::ManageBilling->value);

        if ($organisation->root_id !== null) {
            throw ValidationException::withMessages(['module' => 'Billing is managed by your main organisation.']);
        }

        if ($organisation->country === null) {
            throw ValidationException::withMessages(['module' => 'Choose your organisation\'s country in Organisation settings first. It decides the currency you pay in.']);
        }

        $price = self::price($module, $interval, $organisation->preferredCurrency());

        if ($price === null) {
            throw ValidationException::withMessages(['module' => 'That plan isn\'t on sale yet.']);
        }

        try {
            return Cache::lock('billing:checkout:'.$organisation->getKey(), 30)
                ->block(10, fn (): Checkout => $this->start($actor, $organisation->refresh(), $module, $price, $returnUrl));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['module' => 'A payment is already being started. Try again in a moment.']);
        }
    }

    /**
     * The Airwallex price id a module is sold at in a currency, or null when it isn't offered.
     */
    public static function price(Module $module, string $interval, string $currency): ?string
    {
        if (! in_array($interval, self::INTERVALS, true) || preg_match('/^[a-z]{3}$/', $currency) !== 1) {
            return null;
        }

        $price = config("billing.prices.{$module->value}.{$currency}.{$interval}");

        return is_string($price) && $price !== '' ? $price : null;
    }

    /**
     * The cache key holding the id of the organisation's open checkout for a module. The subscription webhook forgets it.
     */
    public static function openCheckoutKey(Organisation $organisation, string $type): string
    {
        return "billing:open-checkout:{$organisation->getKey()}:{$type}";
    }

    private function start(User $actor, Organisation $organisation, Module $module, string $price, string $returnUrl): Checkout
    {
        if ($organisation->subscribed($module->value)) {
            throw ValidationException::withMessages(['module' => 'You already pay for this.']);
        }

        $this->closeOpenCheckout($organisation, $module);
        $this->ensureCustomerCurrency($organisation);

        $hadTrial = $organisation->subscriptions()->where('type', $module->value)->exists();
        $trialDays = (int) config('billing.trial_days');

        $checkout = $organisation->newSubscription($module->value, $price)
            ->when(! $hadTrial && $trialDays > 0, fn (SubscriptionBuilder $builder): SubscriptionBuilder => $builder->trialDays($trialDays))
            ->checkout([
                'success_url' => $returnUrl.'?checkout=success',
                'back_url' => $returnUrl.'?checkout=cancelled',
            ], ['email' => $actor->email]);

        $session = $checkout->asAirwallexCheckoutSession();
        $expiresAt = isset($session->expires_at) ? Carbon::parse((string) $session->expires_at) : now()->addDays(30);

        Cache::put(self::openCheckoutKey($organisation, $module->value), (string) $session->id, $expiresAt);

        return $checkout;
    }

    /**
     * Cancel the organisation's open checkout for the module, if it still has one. A checkout that is neither open nor
     * cancelled has been paid and is waiting for its webhook, so a second one is refused until it arrives.
     */
    private function closeOpenCheckout(Organisation $organisation, Module $module): void
    {
        $id = Cache::get(self::openCheckoutKey($organisation, $module->value));

        if (! is_string($id) || $id === '') {
            return;
        }

        $session = $organisation->airwallex()->billingCheckouts->retrieve($id);

        if ($session->status === 'ACTIVE') {
            $organisation->airwallex()->billingCheckouts->cancel($id);
        } elseif ($session->status !== 'CANCELLED') {
            throw ValidationException::withMessages(['module' => 'Your payment is still being confirmed. It usually takes a minute.']);
        }

        Cache::forget(self::openCheckoutKey($organisation, $module->value));
    }

    /**
     * An Airwallex customer keeps the currency it was made with, so an organisation whose country has since moved it to
     * another currency is stopped here instead of being sent to a checkout its customer doesn't match.
     */
    private function ensureCustomerCurrency(Organisation $organisation): void
    {
        if (! $organisation->hasAirwallexId()) {
            return;
        }

        $currency = strtoupper((string) ($organisation->asAirwallexCustomer()->default_billing_currency ?? ''));

        if ($currency !== '' && $currency !== strtoupper($organisation->preferredCurrency())) {
            throw ValidationException::withMessages(['module' => "Your organisation is billed in {$currency}. Contact us to change the currency you pay in."]);
        }
    }
}
