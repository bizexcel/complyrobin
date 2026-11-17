<?php

use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-17 09:34:30', 'UTC'));
    $this->organisation = Organisation::factory()->create();
    Tenancy::run($this->organisation, fn () => $this->organisation->forceFill(['airwallex_id' => 'bcus_sgpv8sltmho8pd165e4'])->save());
});

function recordedSubscription(Organisation $organisation, array $attributes = []): Subscription
{
    return Tenancy::run($organisation, fn (): Subscription => Subscription::factory()->create([
        'airwallex_id' => 'sub_sgpv4ct6kho8pd8ekaz',
        ...$attributes,
    ])->fresh());
}

function reloaded(Organisation $organisation, Subscription $subscription): Subscription
{
    return Tenancy::run($organisation, fn (): Subscription => $subscription->fresh());
}

it('cancels at the end of the billing period, keeping the subscription valid until then', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekaz/update' => ['subscriptions.update.cancel_at_period_end']]);
    $subscription = recordedSubscription($this->organisation);

    Tenancy::run($this->organisation, fn (): Subscription => $subscription->cancel());

    expect(reloaded($this->organisation, $subscription)->ends_at?->toIso8601String())->toBe('2026-12-17T09:33:54+00:00')
        ->and(reloaded($this->organisation, $subscription)->onGracePeriod())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/update') && $request['cancel_at_period_end'] === true);
});

it('ends a cancelled trial when the trial would have ended', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pecoim3/update' => ['subscriptions.update.trial.cancel_at_period_end']]);
    $subscription = recordedSubscription($this->organisation, [
        'airwallex_id' => 'sub_sgpv4ct6kho8pecoim3',
        'airwallex_status' => AirwallexSubscription::STATUS_IN_TRIAL,
        'trial_ends_at' => now()->addDays(14),
    ]);

    Tenancy::run($this->organisation, fn (): Subscription => $subscription->cancel());

    expect(reloaded($this->organisation, $subscription)->ends_at?->equalTo($subscription->trial_ends_at))->toBeTrue();
});

it('cancels now, prorated unless told not to', function (bool $prorate, string $behaviour): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekaz/cancel' => ['subscriptions.cancel']]);
    $subscription = recordedSubscription($this->organisation);

    Tenancy::run($this->organisation, fn (): Subscription => ($prorate ? $subscription : $subscription->noProrate())->cancelNow());

    expect(reloaded($this->organisation, $subscription)->airwallex_status)->toBe(AirwallexSubscription::STATUS_CANCELLED)
        ->and(reloaded($this->organisation, $subscription)->ended())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel') && $request['proration_behavior'] === $behaviour);
})->with([
    'prorated' => [true, 'PRORATED'],
    'not prorated' => [false, 'NONE'],
]);

it('lists the subscription\'s invoices, rendered by Airwallex', function (): void {
    fakeAirwallex(['invoices*' => ['invoices.list']]);
    $subscription = recordedSubscription($this->organisation);

    $invoice = Tenancy::run($this->organisation, fn () => $subscription->invoices()->sole());

    expect($invoice->number)->toBe('INV-PDRGVU8O-0001')
        ->and($invoice->rawTotal())->toBe(49)
        ->and($invoice->isPaid())->toBeFalse()
        ->and($invoice->hostedUrl())->toStartWith('https://invoice.sandbox.airwallex.com/pay')
        ->and($invoice->pdfUrl())->toStartWith('https://invoice.sandbox.airwallex.com/pdf')
        ->and($invoice->date()->toIso8601String())->toBe('2026-11-17T09:33:54+00:00');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'invoices?subscription_id=sub_sgpv4ct6kho8pd8ekaz'));
});

it('formats an invoice total in ringgit', function (): void {
    fakeAirwallex(['invoices*' => ['invoices.list']]);
    $subscription = recordedSubscription($this->organisation);

    expect(Tenancy::run($this->organisation, fn () => $subscription->invoices()->sole()->total()))->toMatch('/^RM\s?49\.00$/u');
})->skip(! extension_loaded('intl'), 'needs the intl extension, as production and CI have');

it('syncs the subscription\'s status from Airwallex', function (): void {
    fakeAirwallex(['subscriptions/sub_sgpv4ct6kho8pd8ekaz' => ['subscriptions.retrieve']]);
    $subscription = recordedSubscription($this->organisation);

    Tenancy::run($this->organisation, fn () => $subscription->syncAirwallexStatus());

    expect(reloaded($this->organisation, $subscription)->airwallex_status)->toBe(AirwallexSubscription::STATUS_UNPAID);
});
