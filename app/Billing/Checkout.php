<?php

namespace App\Billing;

use App\Billing\Airwallex\AirwallexObject;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use JsonSerializable;

/**
 * Laravel Cashier's Checkout, wrapping an Airwallex Billing Checkout (Stripe's Checkout Session). Only subscription
 * checkouts are built so far: Cashier's guest and one-off payment checkouts are left out until they are needed.
 *
 * @implements Arrayable<string, mixed>
 */
final class Checkout implements Arrayable, Jsonable, JsonSerializable, Responsable
{
    /**
     * Create a new checkout session instance.
     */
    public function __construct(
        protected Model $owner,
        protected AirwallexObject $session
    ) {}

    /**
     * Begin a new checkout session.
     *
     * @param  Organisation  $owner
     * @param  array<string, mixed>  $sessionOptions
     * @param  array<string, mixed>  $customerOptions
     */
    public static function create(Model $owner, array $sessionOptions = [], array $customerOptions = []): Checkout
    {
        $data = array_merge([
            'mode' => 'SUBSCRIPTION',
            'currency' => strtoupper($owner->preferredCurrency()),
        ], $sessionOptions);

        $data['billing_customer_id'] = $owner->createOrGetAirwallexCustomer($customerOptions)->id;

        $data['success_url'] = $sessionOptions['success_url'] ?? url('/').'?checkout=success';
        $data['back_url'] = $sessionOptions['back_url'] ?? url('/').'?checkout=cancelled';

        $session = $owner->airwallex()->billingCheckouts->create($data);

        return new self($owner, $session);
    }

    /**
     * Redirect to the checkout session.
     */
    public function redirect(): RedirectResponse
    {
        return Redirect::to((string) $this->session->url, 303);
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }

    /**
     * Get the Checkout Session as an Airwallex Billing Checkout object.
     */
    public function asAirwallexCheckoutSession(): AirwallexObject
    {
        return $this->session;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->asAirwallexCheckoutSession()->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Dynamically get values from the Airwallex object.
     */
    public function __get(string $key): mixed
    {
        return $this->session->{$key};
    }
}
