<?php

use App\Billing\Cashier;
use App\Billing\Subscription;
use App\Billing\SubscriptionItem;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
});

afterEach(function (): void {
    Cashier::$deactivatePastDue = true;
    Cashier::$deactivateIncomplete = true;
});

/**
 * @param  callable(Subscription): mixed  $callback
 */
function withSubscription(Organisation $organisation, callable $callback, ?callable $state = null): mixed
{
    return Tenancy::run($organisation, function () use ($callback, $state): mixed {
        $factory = Subscription::factory();

        return $callback(($state ? $state($factory) : $factory)->create()->fresh());
    });
}

it('reads a subscription\'s status as Cashier does', function (string $state, array $expected): void {
    $actual = withSubscription($this->organisation, fn (Subscription $subscription): array => [
        'valid' => $subscription->valid(),
        'active' => $subscription->active(),
        'incomplete' => $subscription->incomplete(),
        'pastDue' => $subscription->pastDue(),
        'onTrial' => $subscription->onTrial(),
        'canceled' => $subscription->canceled(),
        'onGracePeriod' => $subscription->onGracePeriod(),
        'ended' => $subscription->ended(),
        'recurring' => $subscription->recurring(),
    ], fn ($factory) => match ($state) {
        'active' => $factory->active(),
        'trialing' => $factory->trialing(now()->addDays(14)),
        'incomplete' => $factory->incomplete(),
        'past due' => $factory->pastDue(),
        'unpaid' => $factory->unpaid(),
        'on grace period' => $factory->active()->state(['ends_at' => now()->addDays(5)]),
        'canceled and ended' => $factory->canceled()->state(['ends_at' => now()->subDay()]),
    });

    expect($actual)->toBe($expected);
})->with([
    'active' => ['active', ['valid' => true, 'active' => true, 'incomplete' => false, 'pastDue' => false, 'onTrial' => false, 'canceled' => false, 'onGracePeriod' => false, 'ended' => false, 'recurring' => true]],
    'trialing' => ['trialing', ['valid' => true, 'active' => true, 'incomplete' => false, 'pastDue' => false, 'onTrial' => true, 'canceled' => false, 'onGracePeriod' => false, 'ended' => false, 'recurring' => false]],
    'incomplete' => ['incomplete', ['valid' => false, 'active' => false, 'incomplete' => true, 'pastDue' => false, 'onTrial' => false, 'canceled' => false, 'onGracePeriod' => false, 'ended' => false, 'recurring' => true]],
    'past due' => ['past due', ['valid' => false, 'active' => false, 'incomplete' => false, 'pastDue' => true, 'onTrial' => false, 'canceled' => false, 'onGracePeriod' => false, 'ended' => false, 'recurring' => true]],
    'unpaid' => ['unpaid', ['valid' => false, 'active' => false, 'incomplete' => false, 'pastDue' => false, 'onTrial' => false, 'canceled' => false, 'onGracePeriod' => false, 'ended' => false, 'recurring' => true]],
    'on grace period' => ['on grace period', ['valid' => true, 'active' => true, 'incomplete' => false, 'pastDue' => false, 'onTrial' => false, 'canceled' => true, 'onGracePeriod' => true, 'ended' => false, 'recurring' => false]],
    'canceled and ended' => ['canceled and ended', ['valid' => false, 'active' => false, 'incomplete' => false, 'pastDue' => false, 'onTrial' => false, 'canceled' => true, 'onGracePeriod' => false, 'ended' => true, 'recurring' => false]],
]);

it('keeps past due and incomplete subscriptions active when told to', function (): void {
    Cashier::keepPastDueSubscriptionsActive();
    Cashier::keepIncompleteSubscriptionsActive();

    expect(withSubscription($this->organisation, fn (Subscription $subscription): bool => $subscription->active(), fn ($factory) => $factory->pastDue()))->toBeTrue()
        ->and(withSubscription($this->organisation, fn (Subscription $subscription): bool => $subscription->active(), fn ($factory) => $factory->incomplete()))->toBeTrue();
});

it('filters subscriptions with the same rules as the predicates', function (): void {
    Tenancy::run($this->organisation, function (): void {
        Subscription::factory()->active()->create(['type' => 'credentials']);
        Subscription::factory()->trialing(now()->addDays(3))->create(['type' => 'isms']);
        Subscription::factory()->pastDue()->create(['type' => 'bcms']);
        Subscription::factory()->active()->create(['type' => 'grace', 'ends_at' => now()->addDay()]);
        Subscription::factory()->canceled()->create(['type' => 'ended', 'ends_at' => now()->subDay()]);

        $types = fn (string $scope): array => Subscription::query()->{$scope}()->orderBy('type')->pluck('type')->all();

        expect($types('active'))->toBe(['credentials', 'grace', 'isms'])
            ->and($types('pastDue'))->toBe(['bcms'])
            ->and($types('onTrial'))->toBe(['isms'])
            ->and($types('onGracePeriod'))->toBe(['grace'])
            ->and($types('ended'))->toBe(['ended'])
            ->and($types('recurring'))->toBe(['bcms', 'credentials']);
    });
});

it('tells whether the organisation is subscribed to a module and price', function (): void {
    Tenancy::run($this->organisation, function (): void {
        $subscription = Subscription::factory()->withPrice('price_team')->create(['type' => 'credentials']);
        SubscriptionItem::factory()->create(['subscription_id' => $subscription->id, 'airwallex_price' => 'price_team', 'airwallex_product' => 'prod_credentials']);
        Subscription::factory()->pastDue()->create(['type' => 'isms']);

        $organisation = $this->organisation->fresh();

        expect($organisation->subscribed('credentials'))->toBeTrue()
            ->and($organisation->subscribed('credentials', 'price_team'))->toBeTrue()
            ->and($organisation->subscribed('credentials', 'price_other'))->toBeFalse()
            ->and($organisation->subscribedToPrice('price_team', 'credentials'))->toBeTrue()
            ->and($organisation->subscribedToProduct('prod_credentials', 'credentials'))->toBeTrue()
            ->and($organisation->onPrice('price_team'))->toBeTrue()
            ->and($organisation->subscribed('isms'))->toBeFalse()
            ->and($organisation->subscribed('bcms'))->toBeFalse()
            ->and($organisation->subscription('credentials')?->hasSinglePrice())->toBeTrue();
    });
});

it('puts the organisation on a generic trial', function (): void {
    Tenancy::run($this->organisation, function (): void {
        $this->organisation->forceFill(['trial_ends_at' => now()->addDays(14)])->save();

        $organisation = $this->organisation->fresh();

        expect($organisation->onTrial())->toBeTrue()
            ->and($organisation->onGenericTrial())->toBeTrue()
            ->and($organisation->hasExpiredTrial())->toBeFalse()
            ->and($organisation->trialEndsAt()?->isSameDay(now()->addDays(14)))->toBeTrue()
            ->and($organisation->subscribed('credentials'))->toBeFalse();
    });
});

it('finds the billable organisation by its Airwallex id', function (): void {
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'cus_acme'])->save());

    expect(Cashier::findBillable('cus_acme')?->is($this->organisation))->toBeTrue()
        ->and(Cashier::findBillable('cus_unknown'))->toBeNull()
        ->and(Cashier::findBillable(null))->toBeNull();
});
