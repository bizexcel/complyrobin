<?php

namespace App\Billing\Actions;

use App\Billing\Subscription;
use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a module's subscription at the end of the period already paid for (Cashier's `cancel()`): it keeps working
 * until then. Airwallex can't undo a cancellation, so the organisation subscribes again afterwards. Needs billing.manage.
 */
final class CancelSubscription
{
    public function handle(User $actor, Organisation $organisation, Module $module): Subscription
    {
        Gate::forUser($actor)->authorize(Ability::ManageBilling->value);

        $subscription = $organisation->subscription($module->value);

        if (! $subscription instanceof Subscription || ! $subscription->valid() || $subscription->canceled()) {
            throw ValidationException::withMessages(['module' => 'There\'s nothing to cancel.']);
        }

        $subscription->cancel();
        app(Entitlements::class)->forget($organisation);

        return $subscription;
    }
}
