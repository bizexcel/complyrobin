<?php

use App\Billing\Cashier;
use App\Billing\Exceptions\AirwallexApiError;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-17 09:34:00', 'UTC'));
});

it('logs in once, then sends each call with the token and the pinned API version', function (): void {
    fakeAirwallex([
        'billing_customers/bcus_sgpv8sltmho8pd165e4' => ['billing_customers.retrieve'],
        'billing_customers/create' => ['billing_customers.create', 201],
    ]);

    Cashier::airwallex()->billingCustomers->retrieve('bcus_sgpv8sltmho8pd165e4');
    $customer = Cashier::airwallex()->billingCustomers->create(['name' => 'Acme Sdn Bhd']);

    expect($customer->id)->toBe('bcus_sgpv8sltmho8pd165e4');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-demo.airwallex.com/api/v1/authentication/login'
        && $request->header('x-client-id') === ['test-client']
        && $request->header('x-api-key') === ['test-key']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/billing/billing_customers/create')
        && $request->header('Authorization') === ['Bearer REDACTED']
        && $request->header('x-api-version') === ['2025-06-16']
        && Str::isUuid($request['request_id'] ?? '')
        && $request['name'] === 'Acme Sdn Bhd');
});

it('logs in again when a token stops working', function (): void {
    config(['billing.client_id' => 'test-client', 'billing.api_key' => 'test-key', 'billing.env' => 'demo']);
    Http::preventStrayRequests();
    Http::fake([
        'api-demo.airwallex.com/api/v1/authentication/login' => Http::response(airwallexFixture('authentication.login'), 201),
        'api-demo.airwallex.com/api/v1/billing/subscriptions/*' => Http::sequence()
            ->push(airwallexFixture('authentication.login.error.bad_key'), 401)
            ->push(airwallexFixture('subscriptions.retrieve')),
    ]);

    expect(Cashier::airwallex()->subscriptions->retrieve('sub_sgpv4ct6kho8pd8ekaz')->status)->toBe('UNPAID');

    Http::assertSentCount(4);
});

it('turns a refusal into an AirwallexApiError with Airwallex\'s code and field', function (): void {
    fakeAirwallex(['billing_checkouts/create' => ['billing_checkouts.create.error.subscription_data_required', 400]]);

    try {
        Cashier::airwallex()->billingCheckouts->create(['mode' => 'SUBSCRIPTION']);
        $this->fail('Expected an AirwallexApiError.');
    } catch (AirwallexApiError $error) {
        expect($error->httpStatus)->toBe(400)
            ->and($error->airwallexCode)->toBe(airwallexFixture('billing_checkouts.create.error.subscription_data_required')['code']);
    }
});

it('reports a missing subscription as not found', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekzz' => ['subscriptions.retrieve.error.not_found', 404]]);

    expect(fn () => Cashier::airwallex()->subscriptions->retrieve('sub_sgpv4ct6kho8pd8ekzz'))
        ->toThrow(AirwallexApiError::class, 'The Subscription with ID sub_sgpv4ct6kho8pd8ekzz cannot be found.');
});

it('refuses to go on with a bad API key', function (): void {
    config(['billing.client_id' => 'test-client', 'billing.api_key' => 'wrong', 'billing.env' => 'demo']);
    Http::preventStrayRequests();
    Http::fake(['api-demo.airwallex.com/api/v1/authentication/login' => Http::response(airwallexFixture('authentication.login.error.bad_key'), 401)]);

    expect(fn () => Cashier::airwallex()->subscriptions->retrieve('sub_sgpv4ct6kho8pd8ekaz'))
        ->toThrow(AirwallexApiError::class);
});
