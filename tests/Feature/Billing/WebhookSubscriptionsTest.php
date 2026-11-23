<?php

use App\Billing\Actions\StartSubscriptionCheckout;
use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use App\Billing\SubscriptionItem;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The event envelopes here follow Airwallex's documented shape ({id, name, data.object}); only the object's id is read.
 * Every subscription the handlers see is a response recorded from the demo API.
 */

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-17 09:40:00', 'UTC'));
    config(['billing.webhook.secret' => 'whsec_test']);
    // The organisation the recorded subscriptions were tagged with.
    $this->organisation = Organisation::factory()->create(['id' => '0195f0a0-0000-7000-8000-000000000001']);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'bcus_sgpv8sltmho8pd165e4'])->save());
});

function subscriptionEvent(string $name, string $subscriptionId = 'sub_sgpv4ct6kho8pd8ekaz'): array
{
    return airwallexEvent(['name' => $name, 'data' => ['object' => ['id' => $subscriptionId]]]);
}

function localSubscription(Organisation $organisation): ?Subscription
{
    return Tenancy::run($organisation, fn (): ?Subscription => $organisation->subscriptions()->with('items')->first());
}

it('records a new subscription with its items', function (): void {
    fakeAirwallex([
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.retrieve'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz/items' => ['subscriptions.items'],
    ]);

    postWebhook(subscriptionEvent('subscription.created'))->assertOk();

    $subscription = localSubscription($this->organisation);
    expect($subscription)->type->toBe('credentials')
        ->airwallex_status->toBe(AirwallexSubscription::STATUS_UNPAID)
        ->airwallex_price->toBe('pri_sgpvng7wjho8pcx79vl')
        ->quantity->toBe(1)
        ->ends_at->toBeNull()
        ->and($subscription->items->sole())->airwallex_id->toBe('sit_sgpv4ct6kho8pd8ekb0')
        ->airwallex_product->toBe('prd_sgpvng7wjho8pct0ieo');
});

it('starts a trial subscription on trial, which unlocks the module at once', function (): void {
    fakeAirwallex([
        'subscriptions/sub_sgpv4ct6kho8pecoim3' => ['subscriptions.create.trial'],
        'subscriptions/sub_sgpv4ct6kho8pecoim3/items' => ['subscriptions.items'],
    ]);
    // Cached before the webhook arrives, and cleared by it.
    expect(Tenancy::run($this->organisation, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeFalse();

    postWebhook(subscriptionEvent('subscription.created', 'sub_sgpv4ct6kho8pecoim3'))->assertOk();

    expect(localSubscription($this->organisation))->onTrial()->toBeTrue()
        ->trial_ends_at->toIso8601String()->toBe('2026-12-01T09:35:01+00:00')
        ->and(Tenancy::run($this->organisation, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeTrue();
});

it('keeps the subscription in step when Airwallex changes it, including a cancellation at the period end', function (): void {
    fakeAirwallex([
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.update.cancel_at_period_end'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz/items' => ['subscriptions.items'],
    ]);
    Tenancy::run($this->organisation, fn () => Subscription::factory()->active()->create(['type' => 'credentials', 'airwallex_id' => 'sub_sgpv4ct6kho8pd8ekaz']));

    postWebhook(subscriptionEvent('subscription.modified'))->assertOk();

    expect(localSubscription($this->organisation))->airwallex_status->toBe(AirwallexSubscription::STATUS_UNPAID)
        ->ends_at->toIso8601String()->toBe('2026-12-17T09:33:54+00:00')
        ->airwallex_price->toBe('pri_sgpvng7wjho8pcx79vl');
});

it('ends the subscription when Airwallex cancels it, locking the module', function (string $event): void {
    fakeAirwallex([
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.cancel'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz/items' => ['subscriptions.items'],
    ]);
    Tenancy::run($this->organisation, fn () => Subscription::factory()->active()->create(['type' => 'credentials', 'airwallex_id' => 'sub_sgpv4ct6kho8pd8ekaz']));
    expect(Tenancy::run($this->organisation, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeTrue();

    postWebhook(subscriptionEvent($event))->assertOk();

    expect(localSubscription($this->organisation))->airwallex_status->toBe(AirwallexSubscription::STATUS_CANCELLED)
        ->ended()->toBeTrue()
        ->and(Tenancy::run($this->organisation, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeFalse();
})->with(['subscription.cancelled', 'subscription.modified']);

it('ignores another app\'s subscription on the shared Airwallex account', function (): void {
    $otherApp = airwallexFixture('subscriptions.retrieve');
    $otherApp['metadata'] = ['app' => 'cliniclah', 'type' => 'pro'];
    config(['billing.client_id' => 'test-client', 'billing.api_key' => 'test-key', 'billing.env' => 'demo']);
    Http::preventStrayRequests();
    Http::fake([
        'api-demo.airwallex.com/api/v1/authentication/login' => Http::response(airwallexFixture('authentication.login'), 201),
        'api-demo.airwallex.com/api/v1/billing/subscriptions/sub_sgpv4ct6kho8pd8ekaz' => Http::response($otherApp),
    ]);

    postWebhook(subscriptionEvent('subscription.created'))->assertOk();

    expect(localSubscription($this->organisation))->toBeNull();
});

it('ignores a subscription whose customer isn\'t a ComplyRobin organisation', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.retrieve']]);
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'bcus_someone_else'])->save());

    postWebhook(subscriptionEvent('subscription.created'))->assertOk();

    expect(localSubscription($this->organisation))->toBeNull()
        ->and(SubscriptionItem::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('acknowledges events about things other than subscriptions without calling Airwallex', function (): void {
    fakeAirwallex([]);

    postWebhook(airwallexEvent(['name' => 'subscription.created', 'data' => ['object' => ['id' => 'bco_sgpv5qsx9ho8pdh0vq9']]]))->assertOk();

    Http::assertNothingSent();
});

it('brings the subscription up to date when its invoice is paid', function (): void {
    fakeAirwallex([
        'invoices/inv_sgpv4ct6kho8pd8ewnj' => ['invoices.retrieve'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.retrieve'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz/items' => ['subscriptions.items'],
    ]);
    Tenancy::run($this->organisation, fn () => Subscription::factory()->active()->create(['type' => 'credentials', 'airwallex_id' => 'sub_sgpv4ct6kho8pd8ekaz', 'airwallex_price' => 'pri_old']));

    postWebhook(airwallexEvent(['name' => 'invoice.payment.paid', 'data' => ['object' => ['id' => 'inv_sgpv4ct6kho8pd8ewnj']]]))->assertOk();

    expect(localSubscription($this->organisation))->airwallex_price->toBe('pri_sgpvng7wjho8pcx79vl')
        ->airwallex_status->toBe(AirwallexSubscription::STATUS_UNPAID);
});

it('asks Airwallex before opening the database transaction, never inside it', function (): void {
    fakeAirwallex([]);
    $baseline = DB::transactionLevel();
    $levels = [];
    Http::fake([
        'api-demo.airwallex.com/api/v1/billing/*' => function (Request $request) use (&$levels): PromiseInterface {
            $levels[] = DB::transactionLevel();

            return Http::response(airwallexFixture(str_ends_with($request->url(), '/items') ? 'subscriptions.items' : 'subscriptions.retrieve'));
        },
    ]);

    postWebhook(subscriptionEvent('subscription.created'))->assertOk();

    expect($levels)->toHaveCount(2)
        ->and(array_unique($levels))->toBe([$baseline])
        ->and(localSubscription($this->organisation))->not->toBeNull();
});

it('forgets the organisation\'s open checkout once its subscription arrives', function (): void {
    fakeAirwallex([
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.retrieve'],
        'subscriptions/sub_sgpv4ct6kho8pd8ekaz/items' => ['subscriptions.items'],
    ]);
    Cache::put(StartSubscriptionCheckout::openCheckoutKey($this->organisation, 'credentials'), 'bco_sgpv5qsx9ho8pdh0vq9', now()->addDay());

    postWebhook(subscriptionEvent('subscription.created'))->assertOk();

    expect(Cache::has(StartSubscriptionCheckout::openCheckoutKey($this->organisation, 'credentials')))->toBeFalse();
});
