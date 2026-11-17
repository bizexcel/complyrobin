<?php

namespace App\Billing\Http\Controllers;

use App\Billing\Airwallex\AirwallexObject;
use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Cashier;
use App\Billing\Events\WebhookHandled;
use App\Billing\Events\WebhookReceived;
use App\Billing\Http\Middleware\VerifyWebhookSignature;
use App\Billing\Subscription;
use App\Billing\WebhookCall;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    /**
     * Create a new WebhookController instance. Unlike Cashier, the signature is always verified: without a webhook
     * secret every call is refused.
     */
    public function __construct()
    {
        $this->middleware(VerifyWebhookSignature::class);
    }

    /**
     * Handle an Airwallex webhook call.
     *
     * Each event is stored once, by its id, in the same transaction as its handler, so a repeated delivery is
     * acknowledged without being handled again, and a handler that fails leaves nothing stored for the retry to skip.
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['name'] ?? null)) {
            return new Response('Invalid payload', 400);
        }

        /** @var array{id: string, name: string}&array<string, mixed> $payload */
        $method = 'handle'.Str::studly(str_replace('.', '_', $payload['name']));

        return DB::transaction(function () use ($payload, $method): Response {
            if (! WebhookCall::record($payload)) {
                return $this->successMethod();
            }

            WebhookReceived::dispatch($payload);

            if (method_exists($this, $method)) {
                $response = $this->{$method}($payload);

                WebhookHandled::dispatch($payload);

                return $response;
            }

            return $this->missingMethod($payload);
        });
    }

    /**
     * Handle subscription created.
     *
     * Unlike Cashier, each handler fetches the subscription from Airwallex instead of reading the event's object: the
     * fetched one is the latest state whatever order events arrive in, and it is the shape recorded in the fixtures.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(array $payload): Response
    {
        [$user, $data] = $this->subscriptionFromEvent($payload);

        if ($user instanceof Organisation && $data instanceof AirwallexObject) {
            Tenancy::run($user, function () use ($user, $data): void {
                if (! $user->subscriptions()->where('airwallex_id', $data->id)->exists()) {
                    $items = $this->subscriptionItems($data);
                    $firstItem = $items[0] ?? null;
                    $isSinglePrice = count($items) === 1;

                    $subscription = $user->subscriptions()->updateOrCreate([
                        'airwallex_id' => $data->id,
                    ], [
                        'type' => $data->metadata['type'],
                        'airwallex_status' => $data->status,
                        'airwallex_price' => $isSinglePrice ? $firstItem['price']['id'] : null,
                        'quantity' => $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null,
                        'trial_ends_at' => isset($data->trial_ends_at) ? Carbon::parse($data->trial_ends_at) : null,
                        'ends_at' => null,
                    ]);

                    $this->updateSubscriptionItems($subscription, $items);
                }

                // Terminate the billable's generic trial if it exists...
                if (! is_null($user->trial_ends_at)) {
                    $user->trial_ends_at = null;
                    $user->save();
                }
            });

            app(Entitlements::class)->forget($user);
        }

        return $this->successMethod();
    }

    /**
     * Handle subscription in trial.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionInTrial(array $payload): Response
    {
        return $this->handleSubscriptionUpdated($payload);
    }

    /**
     * Handle subscription active.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionActive(array $payload): Response
    {
        return $this->handleSubscriptionUpdated($payload);
    }

    /**
     * Handle subscription unpaid.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUnpaid(array $payload): Response
    {
        return $this->handleSubscriptionUpdated($payload);
    }

    /**
     * Handle subscription modified.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionModified(array $payload): Response
    {
        return $this->handleSubscriptionUpdated($payload);
    }

    /**
     * Handle subscription updated: Cashier's handleCustomerSubscriptionUpdated, which Airwallex's in_trial, active,
     * unpaid and modified events share.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(array $payload): Response
    {
        [$user, $data] = $this->subscriptionFromEvent($payload);

        if ($user instanceof Organisation && $data instanceof AirwallexObject) {
            Tenancy::run($user, function () use ($user, $data): void {
                $subscription = $user->subscriptions()->firstOrNew(['airwallex_id' => $data->id]);

                $subscription->type ??= $data->metadata['type'];

                $items = $this->subscriptionItems($data);
                $firstItem = $items[0] ?? null;
                $isSinglePrice = count($items) === 1;

                // Price...
                $subscription->airwallex_price = $isSinglePrice ? $firstItem['price']['id'] : null;

                // Quantity...
                $subscription->quantity = $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null;

                // Trial ending date...
                if (isset($data->trial_ends_at)) {
                    $trialEnd = Carbon::parse($data->trial_ends_at);

                    if (! $subscription->trial_ends_at || $subscription->trial_ends_at->ne($trialEnd)) {
                        $subscription->trial_ends_at = $trialEnd;
                    }
                }

                // Status...
                $subscription->airwallex_status = $data->status;

                // Cancellation date...
                if ($data->cancel_at_period_end ?? false) {
                    $subscription->ends_at = $subscription->onTrial()
                        ? $subscription->trial_ends_at
                        : Carbon::parse($data->current_period_ends_at);
                } elseif ($data->status === AirwallexSubscription::STATUS_CANCELLED) {
                    $subscription->ends_at = $subscription->ends_at?->isPast() ? $subscription->ends_at : Carbon::now();
                } else {
                    $subscription->ends_at = null;
                }

                $subscription->save();

                // Update subscription items...
                $this->updateSubscriptionItems($subscription, $items);
            });

            app(Entitlements::class)->forget($user);
        }

        return $this->successMethod();
    }

    /**
     * Handle the cancellation of a subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCancelled(array $payload): Response
    {
        [$user, $data] = $this->subscriptionFromEvent($payload);

        if ($user instanceof Organisation && $data instanceof AirwallexObject) {
            Tenancy::run($user, function () use ($user, $data): void {
                $user->subscriptions()->where('airwallex_id', $data->id)->get()
                    ->reject(fn (Subscription $subscription): bool => $subscription->ended())
                    ->each(function (Subscription $subscription): void {
                        $subscription->markAsCanceled();
                    });
            });

            app(Entitlements::class)->forget($user);
        }

        return $this->successMethod();
    }

    /**
     * The organisation and the Airwallex subscription an event is about, or nulls when the subscription isn't
     * ComplyRobin's: the Airwallex account is shared with other apps, whose events this endpoint receives too.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: Organisation|null, 1: AirwallexObject|null}
     */
    protected function subscriptionFromEvent(array $payload): array
    {
        $id = data_get($payload, 'data.object.id');

        if (! is_string($id) || ! str_starts_with($id, 'sub_')) {
            return [null, null];
        }

        $data = Cashier::airwallex()->subscriptions->retrieve($id);
        $metadata = (array) $data->metadata;

        if (($metadata['app'] ?? null) !== 'complyrobin' || Module::tryFrom((string) ($metadata['type'] ?? '')) === null) {
            return [null, null];
        }

        $user = $this->getUserByAirwallexId($data->billing_customer_id);

        if (! $user instanceof Organisation || (isset($metadata['organisation_id']) && $metadata['organisation_id'] !== $user->getKey())) {
            Log::warning('billing.webhook.unmatched_subscription', ['subscription' => $id, 'event' => $payload['id'] ?? null]);

            return [null, null];
        }

        return [$user, $data];
    }

    /**
     * The subscription's items, as Airwallex lists them.
     *
     * @return list<array{id: string, quantity?: int, price: array{id: string, product_id: string}}>
     */
    protected function subscriptionItems(AirwallexObject $data): array
    {
        /** @var list<array{id: string, quantity?: int, price: array{id: string, product_id: string}}> */
        return array_values((array) Cashier::airwallex()->subscriptions->items($data->id)->items);
    }

    /**
     * Update the subscription's items, deleting those no longer attached to it.
     *
     * @param  list<array{id: string, quantity?: int, price: array{id: string, product_id: string}}>  $items
     */
    protected function updateSubscriptionItems(Subscription $subscription, array $items): void
    {
        $subscriptionItemIds = [];

        foreach ($items as $item) {
            $subscriptionItemIds[] = $item['id'];

            $subscription->items()->updateOrCreate([
                'airwallex_id' => $item['id'],
            ], [
                'airwallex_product' => $item['price']['product_id'],
                'airwallex_price' => $item['price']['id'],
                'quantity' => $item['quantity'] ?? null,
            ]);
        }

        // Delete items that aren't attached to the subscription anymore...
        $subscription->items()->whereNotIn('airwallex_id', $subscriptionItemIds)->delete();
    }

    /**
     * Get the customer instance by Airwallex ID.
     */
    protected function getUserByAirwallexId(?string $airwallexId): ?Model
    {
        return Cashier::findBillable($airwallexId);
    }

    /**
     * Handle successful calls on the controller.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function successMethod(array $parameters = []): Response
    {
        return new Response('Webhook Handled', 200);
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function missingMethod(array $parameters = []): Response
    {
        return new Response;
    }
}
