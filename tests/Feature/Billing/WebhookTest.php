<?php

use App\Billing\Airwallex\WebhookSignature;
use App\Billing\Events\WebhookHandled;
use App\Billing\Events\WebhookReceived;
use App\Billing\WebhookCall;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['billing.webhook.secret' => 'whsec_test', 'billing.webhook.tolerance' => 300]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function airwallexEvent(array $overrides = []): array
{
    return [
        'id' => 'evt_'.Str::random(24),
        'name' => 'subscription.created',
        'account_id' => 'acct_test',
        'data' => ['object' => ['id' => 'sub_test']],
        'created_at' => now()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * Post the event as Airwallex would: the raw body, signed with the timestamp in milliseconds.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?string $secret = 'whsec_test', ?int $timestampMs = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) ($timestampMs ?? now()->getTimestampMs());

    return test()->call('POST', route('cashier.webhook'), server: [
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => $secret === null ? '' : WebhookSignature::computeSignature($timestamp, $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}

function storedWebhookCalls(): int
{
    return Tenancy::bypass('test: count the stored webhook calls', fn (): int => WebhookCall::query()->count());
}

it('accepts a signed call, stores it and fires Cashier\'s events', function (): void {
    Event::fake([WebhookReceived::class, WebhookHandled::class]);

    postWebhook(airwallexEvent())->assertOk();

    expect(storedWebhookCalls())->toBe(1);
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
    Event::assertNotDispatched(WebhookHandled::class); // no handle{Event} method for it yet
});

it('refuses a call that is unsigned, signed with another secret, or signed too long ago', function (?string $secret, int $ageSeconds): void {
    Event::fake([WebhookReceived::class]);

    postWebhook(airwallexEvent(), $secret, now()->subSeconds($ageSeconds)->getTimestampMs())->assertForbidden();

    expect(storedWebhookCalls())->toBe(0);
    Event::assertNotDispatched(WebhookReceived::class);
})->with([
    'unsigned' => [null, 0],
    'another secret' => ['whsec_other', 0],
    'stale' => ['whsec_test', 301],
    'from the future' => ['whsec_test', -301],
]);

it('refuses every call when no webhook secret is configured', function (): void {
    config(['billing.webhook.secret' => null]);

    postWebhook(airwallexEvent(), 'whsec_test')->assertForbidden();

    expect(storedWebhookCalls())->toBe(0);
});

it('refuses a body changed after it was signed', function (): void {
    $timestamp = (string) now()->getTimestampMs();
    $signature = WebhookSignature::computeSignature($timestamp, json_encode(airwallexEvent(['id' => 'evt_original'])), 'whsec_test');

    $this->call('POST', route('cashier.webhook'), server: [
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: json_encode(airwallexEvent(['id' => 'evt_tampered'])))->assertForbidden();

    expect(storedWebhookCalls())->toBe(0);
});

it('acknowledges a repeated delivery without handling it again', function (): void {
    Event::fake([WebhookReceived::class]);
    $event = airwallexEvent();

    postWebhook($event)->assertOk();
    postWebhook($event)->assertOk();

    expect(storedWebhookCalls())->toBe(1);
    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('keeps nothing from a call whose handling fails, so the retry is handled', function (): void {
    $event = airwallexEvent();
    $attempts = 0;

    Event::listen(WebhookReceived::class, function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new RuntimeException('Handling failed');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn (): TestResponse => postWebhook($event))->toThrow(RuntimeException::class);
    expect(storedWebhookCalls())->toBe(0);

    postWebhook($event)->assertOk();
    expect(storedWebhookCalls())->toBe(1)
        ->and($attempts)->toBe(2);
});

it('rejects a signed body that is not an Airwallex event', function (): void {
    postWebhook(['hello' => 'world'])->assertBadRequest();

    expect(storedWebhookCalls())->toBe(0);
});

it('does not let the app read or change stored webhook calls', function (): void {
    postWebhook(airwallexEvent())->assertOk();

    expect(fn () => WebhookCall::query()->count())->toThrow(QueryException::class);
});
