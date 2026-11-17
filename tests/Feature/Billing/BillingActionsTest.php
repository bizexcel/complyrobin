<?php

use App\Billing\Actions\CancelSubscription;
use App\Billing\Actions\StartSubscriptionCheckout;
use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Enums\Module;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['billing.prices.credentials' => [
        'pro' => ['myr' => ['monthly' => 'pri_sgpvng7wjho8pcx79vl', 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]],
        'team' => ['myr' => ['monthly' => 'pri_team_myr_monthly_not_recorded', 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]],
    ]]);
    fakeAirwallex([]);
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme', 'MY');
});

function startCheckout(User $actor, string $interval = 'monthly', Plan $plan = Plan::Pro): mixed
{
    return Tenancy::run(test()->organisation, fn () => app(StartSubscriptionCheckout::class)
        ->handle($actor, test()->organisation->fresh(), Module::Credentials, $plan, $interval, 'https://app.complyrobin.test/acme/billing'));
}

it('needs the billing permission', function (): void {
    expect(fn (): mixed => startCheckout(memberWithRole($this->organisation, SystemRole::Editor)))->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

it('refuses a plan that isn\'t on sale', function (): void {
    expect(fn (): mixed => startCheckout($this->owner, 'yearly'))->toThrow(ValidationException::class, 'That plan isn\'t on sale yet.')
        ->and(fn (): mixed => startCheckout($this->owner, 'weekly'))->toThrow(ValidationException::class);
});

it('needs the organisation\'s country, which decides the currency', function (): void {
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['country' => null])->save());

    expect(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'Choose your organisation\'s country in Organisation settings first.');
    Http::assertNothingSent();
});

it('refuses a plan with no price in the organisation\'s currency', function (): void {
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['country' => 'US'])->save());

    expect(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'That plan isn\'t on sale yet.');
});

it('refuses a second subscription to the same module', function (): void {
    Tenancy::run($this->organisation, fn () => Subscription::factory()->create(['type' => 'credentials']));

    expect(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'You already pay for this.');
    Http::assertNothingSent();
});

it('points an organisation on Pro to us to move to Team, instead of a second subscription', function (): void {
    Tenancy::run($this->organisation, fn () => Subscription::factory()->create(['type' => 'credentials', 'airwallex_price' => 'pri_sgpvng7wjho8pcx79vl']));

    expect(fn (): mixed => startCheckout($this->owner, plan: Plan::Team))
        ->toThrow(ValidationException::class, "You're on Pro. To move to Team, contact us and we'll switch your plan with no gap.")
        ->and(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'You already pay for this.');
    Http::assertNothingSent();
});

it('cancels only a subscription that is still running', function (string $status, array $attributes): void {
    Tenancy::run($this->organisation, fn () => Subscription::factory()->create(['type' => 'credentials', 'airwallex_status' => $status, ...$attributes]));

    expect(fn () => Tenancy::run($this->organisation, fn () => app(CancelSubscription::class)->handle($this->owner, $this->organisation->fresh(), Module::Credentials)))
        ->toThrow(ValidationException::class, 'There\'s nothing to cancel.');
    Http::assertNothingSent();
})->with([
    'already cancelled' => [AirwallexSubscription::STATUS_ACTIVE, ['ends_at' => now()->addMonth()]],
    'ended' => [AirwallexSubscription::STATUS_CANCELLED, ['ends_at' => now()->subDay()]],
]);

it('needs the billing permission to cancel', function (): void {
    expect(fn () => Tenancy::run($this->organisation, fn () => app(CancelSubscription::class)
        ->handle(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation->fresh(), Module::Credentials)))
        ->toThrow(AuthorizationException::class);
});

it('gives a free trial only the first time an organisation subscribes to a module', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
    ]);
    Tenancy::run($this->organisation, fn () => Subscription::factory()->canceled()->create(['type' => 'credentials', 'ends_at' => now()->subDay()]));

    startCheckout($this->owner);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'billing_checkouts/create')
        && ! isset($request['subscription_data']['trial_ends_at']));
});
