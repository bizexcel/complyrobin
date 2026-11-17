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
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['billing.prices.credentials' => ['monthly' => 'pri_sgpvng7wjho8pcx79vl', 'yearly' => null]]);
    fakeAirwallex([]);
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
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
