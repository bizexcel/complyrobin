<?php

namespace App\Billing\Airwallex;

use App\Billing\Airwallex\Resources\BillingCheckouts;
use App\Billing\Airwallex\Resources\BillingCustomers;
use App\Billing\Airwallex\Resources\Invoices;
use App\Billing\Airwallex\Resources\Prices;
use App\Billing\Airwallex\Resources\Products;
use App\Billing\Airwallex\Resources\Subscriptions;
use App\Billing\Exceptions\AirwallexApiError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stripe's `StripeClient`, for Airwallex's Billing API, on Laravel's Http client. Every Airwallex call goes through
 * here. It logs in with the client id and API key, caches the bearer token until a minute before it expires, pins the
 * API version, gives every POST a `request_id` so a retried create is not repeated, retries connection failures and
 * server errors, and turns any refusal into an AirwallexApiError.
 *
 * @property-read BillingCustomers $billingCustomers
 * @property-read Products $products
 * @property-read Prices $prices
 * @property-read Subscriptions $subscriptions
 * @property-read BillingCheckouts $billingCheckouts
 * @property-read Invoices $invoices
 */
final readonly class AirwallexClient
{
    private const array BASE_URLS = [
        'demo' => 'https://api-demo.airwallex.com',
        'production' => 'https://api.airwallex.com',
    ];

    private const array RESOURCES = [
        'billingCustomers' => BillingCustomers::class,
        'products' => Products::class,
        'prices' => Prices::class,
        'subscriptions' => Subscriptions::class,
        'billingCheckouts' => BillingCheckouts::class,
        'invoices' => Invoices::class,
    ];

    /**
     * @param  array{client_id?: string|null, api_key?: string|null, env?: string|null, api_version?: string|null}  $config
     */
    public function __construct(private array $config) {}

    public function __get(string $name): object
    {
        $resource = self::RESOURCES[$name] ?? throw new \InvalidArgumentException("Airwallex has no [{$name}] resource here.");

        return new $resource($this);
    }

    /**
     * Send a request to the Billing API, under `/api/v1/billing/`, and return the response body.
     *
     * @param  array<string, mixed>  $data  the JSON body of a POST, or the query of a GET
     *
     * @throws AirwallexApiError
     */
    public function request(string $method, string $path, array $data = []): AirwallexObject
    {
        $send = fn (string $token): Response => $method === 'GET'
            ? $this->http($token)->get("/api/v1/billing/{$path}", $data)
            : $this->http($token)->post("/api/v1/billing/{$path}", ['request_id' => (string) Str::uuid(), ...$data]);

        $response = $send($this->token());

        // A token can expire early, for example when the key is rotated: log in again once.
        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $response = $send($this->token());
        }

        if ($response->failed()) {
            throw AirwallexApiError::fromResponse($response);
        }

        return new AirwallexObject((array) $response->json());
    }

    private function http(string $token): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->withHeaders(['x-api-version' => $this->config['api_version'] ?? '2025-06-16'])
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(3, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }

    /**
     * The bearer token, from the cache or a fresh login.
     *
     * @throws AirwallexApiError
     */
    private function token(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withHeaders([
                'x-client-id' => (string) ($this->config['client_id'] ?? ''),
                'x-api-key' => (string) ($this->config['api_key'] ?? ''),
            ])
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
            ->post('/api/v1/authentication/login');

        if ($response->failed() || ! is_string($response->json('token'))) {
            throw AirwallexApiError::fromResponse($response);
        }

        $token = $response->json('token');
        $expiresAt = Carbon::parse((string) $response->json('expires_at'))->subMinute();

        if ($expiresAt->isFuture()) {
            Cache::put($this->tokenCacheKey(), $token, $expiresAt);
        }

        return $token;
    }

    private function baseUrl(): string
    {
        return self::BASE_URLS[$this->config['env'] ?? 'demo'] ?? throw new \InvalidArgumentException('AIRWALLEX_ENV must be demo or production.');
    }

    private function tokenCacheKey(): string
    {
        return 'airwallex:token:'.hash('sha256', ($this->config['env'] ?? 'demo').'|'.($this->config['client_id'] ?? ''));
    }
}
