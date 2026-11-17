<?php

use App\Billing\Subscription;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;

beforeEach(function (): void {
    [$this->acme, $this->globex] = Organisation::factory()->count(2)->create();
});

function subscribe(Organisation $organisation, Module $module, ?callable $state = null): Subscription
{
    return Tenancy::run($organisation, function () use ($module, $state): Subscription {
        $factory = Subscription::factory()->state(['type' => $module->value]);

        return ($state ? $state($factory) : $factory)->create();
    });
}

function hasSubscribedModule(Organisation $organisation, Module $module): bool
{
    return Tenancy::run($organisation, fn (): bool => app(Entitlements::class)->has($module));
}

it('gives an organisation the modules it has a valid subscription to, and only those', function (): void {
    subscribe($this->acme, Module::Credentials);

    expect(hasSubscribedModule($this->acme, Module::Credentials))->toBeTrue()
        ->and(hasSubscribedModule($this->acme, Module::Isms))->toBeFalse()
        ->and(hasSubscribedModule($this->globex, Module::Credentials))->toBeFalse()
        ->and(Tenancy::run($this->acme, fn (): Plan => app(Entitlements::class)->plan()))->toBe(Plan::Team);
});

it('gives the plan of the price subscribed to: Pro or Team, and Team for a price no longer listed', function (?string $price, Plan $plan): void {
    config(['billing.prices.credentials' => [
        'pro' => ['myr' => ['monthly' => 'pri_pro_monthly', 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => 'pri_pro_usd_yearly']],
        'team' => ['myr' => ['monthly' => 'pri_team_monthly', 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]],
    ]]);
    subscribe($this->acme, Module::Credentials, fn ($factory) => $factory->state(['airwallex_price' => $price]));

    expect(Tenancy::run($this->acme, fn (): Plan => app(Entitlements::class)->plan()))->toBe($plan);
})->with([
    'Pro, monthly in ringgit' => ['pri_pro_monthly', Plan::Pro],
    'Pro, yearly in dollars' => ['pri_pro_usd_yearly', Plan::Pro],
    'Team' => ['pri_team_monthly', Plan::Team],
    'a retired price' => ['pri_retired', Plan::Team],
]);

it('counts a subscription only while it is valid', function (string $state, bool $isEntitled): void {
    subscribe($this->acme, Module::Isms, fn ($factory) => match ($state) {
        'trialing' => $factory->trialing(now()->addDays(14)),
        'on grace period' => $factory->state(['ends_at' => now()->addDay()]),
        'past due' => $factory->pastDue(),
        'incomplete' => $factory->incomplete(),
        'ended' => $factory->canceled()->state(['ends_at' => now()->subDay()]),
    });

    expect(hasSubscribedModule($this->acme, Module::Isms))->toBe($isEntitled);
})->with([
    'trialing' => ['trialing', true],
    'on grace period' => ['on grace period', true],
    'past due' => ['past due', false],
    'incomplete' => ['incomplete', false],
    'ended' => ['ended', false],
]);

it('shares a root organisation\'s subscriptions with its sub-organisations', function (): void {
    $penang = Organisation::factory()->create(['parent_id' => $this->acme->id, 'root_id' => $this->acme->id]);

    subscribe($this->acme, Module::Bcms);

    expect(hasSubscribedModule($penang, Module::Bcms))->toBeTrue()
        ->and(Tenancy::run($penang, fn (): int => Subscription::query()->count()))->toBe(0);
});
