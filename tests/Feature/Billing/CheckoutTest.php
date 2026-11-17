<?php

use App\Billing\Exceptions\CustomerAlreadyCreated;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-17 09:34:00', 'UTC'));
    $this->organisation = Organisation::factory()->create(['name' => 'Acme Sdn Bhd']);
});

function sentTo(string $path): ?Request
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/api/v1/billing/{$path}"))->first()[0] ?? null;
}

it('makes the organisation an Airwallex business customer, tagged with its id', function (): void {
    fakeAirwallex(['billing_customers/create' => ['billing_customers.create', 201]]);

    Tenancy::run($this->organisation, fn () => $this->organisation->createAsAirwallexCustomer(['email' => 'owner@acme.test']));

    expect($this->organisation->fresh()->airwallexId())->toBe('bcus_sgpv8sltmho8pd165e4')
        ->and(sentTo('billing_customers/create')?->data())->toMatchArray([
            'name' => 'Acme Sdn Bhd',
            'email' => 'owner@acme.test',
            'type' => 'BUSINESS',
            'default_billing_currency' => 'MYR',
            'metadata' => ['app' => 'complyrobin', 'organisation_id' => $this->organisation->id],
        ]);
});

it('refuses to make a second Airwallex customer', function (): void {
    fakeAirwallex([]);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'bcus_existing'])->save());

    expect(fn () => Tenancy::run($this->organisation, fn () => $this->organisation->fresh()->createAsAirwallexCustomer()))
        ->toThrow(CustomerAlreadyCreated::class);
    Http::assertNothingSent();
});

it('updates the Airwallex customer', function (): void {
    fakeAirwallex(['billing_customers/bcus_sgpv8sltmho8pd165e4/update' => ['billing_customers.update']]);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'bcus_sgpv8sltmho8pd165e4'])->save());

    $customer = $this->organisation->fresh()->updateAirwallexCustomer(['name' => 'Spike Test Renamed Sdn Bhd']);

    expect($customer->name)->toBe('Spike Test Renamed Sdn Bhd');
});

it('starts a subscription through an Airwallex Billing Checkout and redirects to it', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
    ]);

    $response = Tenancy::run($this->organisation, fn () => $this->organisation
        ->newSubscription('credentials', 'pri_sgpvng7wjho8pcx79vl')
        ->checkout([
            'success_url' => 'https://app.complyrobin.com/billing/success',
            'back_url' => 'https://app.complyrobin.com/billing',
        ])
        ->toResponse(request()));

    expect($response->getStatusCode())->toBe(303)
        ->and($response->getTargetUrl())->toBe(airwallexFixture('billing_checkouts.create')['url'])
        ->and(sentTo('billing_checkouts/create')?->data())->toMatchArray([
            'mode' => 'SUBSCRIPTION',
            'currency' => 'MYR',
            'billing_customer_id' => 'bcus_sgpv8sltmho8pd165e4',
            'success_url' => 'https://app.complyrobin.com/billing/success',
            'back_url' => 'https://app.complyrobin.com/billing',
            'line_items' => [['price_id' => 'pri_sgpvng7wjho8pcx79vl', 'quantity' => 1]],
            'subscription_data' => [
                'metadata' => ['app' => 'complyrobin', 'organisation_id' => $this->organisation->id, 'type' => 'credentials'],
            ],
        ]);
});

it('starts the subscription with a trial', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create.trial', 201],
    ]);

    Tenancy::run($this->organisation, fn () => $this->organisation
        ->newSubscription('credentials', 'pri_sgpvng7wjho8pcx79vl')
        ->trialDays(14)
        ->checkout());

    expect(sentTo('billing_checkouts/create')?->data()['subscription_data']['trial_ends_at'])->toBe('2026-12-01T09:34:00+0000');
});

it('starts no trial when it is skipped', function (): void {
    fakeAirwallex([
        'billing_customers/create' => ['billing_customers.create', 201],
        'billing_checkouts/create' => ['billing_checkouts.create', 201],
    ]);

    Tenancy::run($this->organisation, fn () => $this->organisation
        ->newSubscription('credentials', 'pri_sgpvng7wjho8pcx79vl')
        ->trialDays(14)
        ->skipTrial()
        ->checkout());

    expect(sentTo('billing_checkouts/create')?->data()['subscription_data'])->not->toHaveKey('trial_ends_at');
});

it('needs a price to start a subscription', function (): void {
    fakeAirwallex([]);

    expect(fn () => $this->organisation->newSubscription('credentials')->checkout())
        ->toThrow(Exception::class, 'At least one price is required when starting subscriptions.');
    Http::assertNothingSent();
});
