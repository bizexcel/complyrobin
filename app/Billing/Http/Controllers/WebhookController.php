<?php

namespace App\Billing\Http\Controllers;

use App\Billing\Cashier;
use App\Billing\Events\WebhookHandled;
use App\Billing\Events\WebhookReceived;
use App\Billing\Http\Middleware\VerifyWebhookSignature;
use App\Billing\WebhookCall;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
