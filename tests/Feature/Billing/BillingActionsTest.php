<?php

use App\Billing\Actions\CancelSubscription;
use App\Billing\Actions\StartSubscriptionCheckout;
use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['billing.prices.credentials' => ['myr' => ['monthly' => 'pri_sgpvng7wjho8pcx79vl', 'yearly' => null], 'usd' => ['monthly' => null, 'yearly' => null]]]);
    fakeAirwallex([]);
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme', 'MY');
});

function startCheckout(User $actor, string $interval = 'monthly'): mixed
{
    return Tenancy::run(test()->organisation, fn () => app(StartSubscriptionCheckout::class)
        ->handle($actor, test()->organisation->fresh(), Module::Credentials, $interval, 'https://app.complyrobin.test/acme/billing'));
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

it('remembers the open checkout and cancels it when another is started, so two tabs can\'t pay twice', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_customers/bcus_sgpv8sltmho8pd165e4' => ['billing_customers.retrieve'],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
        'billing_checkouts/bco_sgpv5qsx9ho8pdh0vq9' => ['billing_checkouts.retrieve'],
        'billing_checkouts/bco_sgpv5qsx9ho8pdh0vq9/cancel' => ['billing_checkouts.cancel'],
    ]);

    startCheckout($this->owner);
    expect(Cache::get(StartSubscriptionCheckout::openCheckoutKey($this->organisation, 'credentials')))->toBe('bco_sgpv5qsx9ho8pdh0vq9');

    startCheckout($this->owner);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'billing_checkouts/bco_sgpv5qsx9ho8pdh0vq9/cancel'));
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), 'billing_customers/create')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), 'billing_checkouts/create')))->toHaveCount(2);
});

it('refuses a new checkout while the last one is paid and waiting for its webhook', function (): void {
    $paid = airwallexFixture('billing_checkouts.retrieve');
    $paid['status'] = 'COMPLETED';
    fakeAirwallex([]);
    Http::fake(['api-demo.airwallex.com/api/v1/billing/billing_checkouts/bco_sgpv5qsx9ho8pdh0vq9' => Http::response($paid)]);
    Cache::put(StartSubscriptionCheckout::openCheckoutKey($this->organisation, 'credentials'), 'bco_sgpv5qsx9ho8pdh0vq9', now()->addDay());

    expect(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'Your payment is still being confirmed.');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'billing_checkouts/create'));
});

it('refuses a checkout in a currency the organisation\'s Airwallex customer wasn\'t made with', function (): void {
    fakeAirwallex(['billing_customers/bcus_sgpv8sltmho8pd165e4' => ['billing_customers.retrieve']]);
    config(['billing.prices.credentials.usd.monthly' => 'pri_usd_monthly']);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['country' => 'US', 'airwallex_id' => 'bcus_sgpv8sltmho8pd165e4'])->save());

    expect(fn (): mixed => startCheckout($this->owner))->toThrow(ValidationException::class, 'Your organisation is billed in MYR.');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'billing_checkouts/create'));
});
