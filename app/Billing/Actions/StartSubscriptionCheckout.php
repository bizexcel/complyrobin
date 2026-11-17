<?php

namespace App\Billing\Actions;

use App\Billing\Checkout;
use App\Billing\SubscriptionBuilder;
use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send the organisation to an Airwallex Billing Checkout for a module, paid monthly or yearly. Needs billing.manage, and
 * only a root organisation pays: its sub-organisations share what it buys. The first subscription to a module starts
 * with a free trial of `billing.trial_days`. The local subscription is written by the
 * webhook once the checkout completes, as in Cashier.
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

        $price = self::price($module, $interval);

        if ($price === null) {
            throw ValidationException::withMessages(['module' => 'That plan isn\'t on sale yet.']);
        }

        if ($organisation->subscribed($module->value)) {
            throw ValidationException::withMessages(['module' => 'You already pay for this.']);
        }

        $hadTrial = $organisation->subscriptions()->where('type', $module->value)->exists();
        $trialDays = (int) config('billing.trial_days');

        return $organisation->newSubscription($module->value, $price)
            ->when(! $hadTrial && $trialDays > 0, fn (SubscriptionBuilder $builder): SubscriptionBuilder => $builder->trialDays($trialDays))
            ->checkout([
                'success_url' => $returnUrl.'?checkout=success',
                'back_url' => $returnUrl.'?checkout=cancelled',
            ], ['email' => $actor->email]);
    }

    /**
     * The Airwallex price id a module is sold at, or null when it isn't offered.
     */
    public static function price(Module $module, string $interval): ?string
    {
        if (! in_array($interval, self::INTERVALS, true)) {
            return null;
        }

        $price = config("billing.prices.{$module->value}.{$interval}");

        return is_string($price) && $price !== '' ? $price : null;
    }
}
