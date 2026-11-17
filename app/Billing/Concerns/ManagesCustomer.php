<?php

namespace App\Billing\Concerns;

use App\Billing\Airwallex\AirwallexObject;
use App\Billing\Exceptions\CustomerAlreadyCreated;
use App\Billing\Exceptions\InvalidCustomer;

/**
 * @property string|null $airwallex_id
 */
trait ManagesCustomer
{
    use InteractsWithAirwallex;

    /**
     * Retrieve the Airwallex customer ID.
     */
    public function airwallexId(): ?string
    {
        return $this->airwallex_id;
    }

    /**
     * Determine if the customer has an Airwallex customer ID.
     */
    public function hasAirwallexId(): bool
    {
        return ! is_null($this->airwallex_id);
    }

    /**
     * Determine if the customer has an Airwallex customer ID and throw an exception if not.
     *
     * @throws InvalidCustomer
     */
    protected function assertCustomerExists(): void
    {
        if (! $this->hasAirwallexId()) {
            throw InvalidCustomer::notYetCreated($this);
        }
    }

    /**
     * Create an Airwallex customer for the given model.
     *
     * Airwallex billing customers are businesses or individuals, and are billed in one currency; ComplyRobin bills
     * businesses, in the configured currency.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws CustomerAlreadyCreated
     */
    public function createAsAirwallexCustomer(array $options = []): AirwallexObject
    {
        if ($this->hasAirwallexId()) {
            throw CustomerAlreadyCreated::exists($this);
        }

        if (! array_key_exists('name', $options) && $name = $this->airwallexName()) {
            $options['name'] = $name;
        }

        if (! array_key_exists('email', $options) && $email = $this->airwallexEmail()) {
            $options['email'] = $email;
        }

        if (! array_key_exists('address', $options) && $address = $this->airwallexAddress()) {
            $options['address'] = $address;
        }

        if (! array_key_exists('metadata', $options) && $metadata = $this->airwallexMetadata()) {
            $options['metadata'] = $metadata;
        }

        $options['type'] ??= 'BUSINESS';
        $options['default_billing_currency'] ??= strtoupper($this->preferredCurrency());

        // Here we will create the customer instance on Airwallex and store the ID of the
        // user from Airwallex. This ID will correspond with the Airwallex user instances
        // and allow us to retrieve users from Airwallex later when we need to work.
        $customer = static::airwallex()->billingCustomers->create($options);

        $this->airwallex_id = $customer->id;

        $this->save();

        return $customer;
    }

    /**
     * Update the underlying Airwallex customer information for the model.
     *
     * @param  array<string, mixed>  $options
     */
    public function updateAirwallexCustomer(array $options = []): AirwallexObject
    {
        $this->assertCustomerExists();

        return static::airwallex()->billingCustomers->update(
            (string) $this->airwallex_id, $options
        );
    }

    /**
     * Get the Airwallex customer instance for the current user or create one.
     *
     * @param  array<string, mixed>  $options
     */
    public function createOrGetAirwallexCustomer(array $options = []): AirwallexObject
    {
        if ($this->hasAirwallexId()) {
            return $this->asAirwallexCustomer();
        }

        return $this->createAsAirwallexCustomer($options);
    }

    /**
     * Update the Airwallex customer information for the current user or create one.
     *
     * @param  array<string, mixed>  $options
     */
    public function updateOrCreateAirwallexCustomer(array $options = []): AirwallexObject
    {
        if ($this->hasAirwallexId()) {
            return $this->updateAirwallexCustomer($options);
        }

        return $this->createAsAirwallexCustomer($options);
    }

    /**
     * Sync the customer's information to Airwallex for the current user or create one.
     *
     * @param  array<string, mixed>  $options
     */
    public function syncOrCreateAirwallexCustomer(array $options = []): AirwallexObject
    {
        if ($this->hasAirwallexId()) {
            return $this->syncAirwallexCustomerDetails();
        }

        return $this->createAsAirwallexCustomer($options);
    }

    /**
     * Get the Airwallex customer for the model.
     */
    public function asAirwallexCustomer(): AirwallexObject
    {
        $this->assertCustomerExists();

        return static::airwallex()->billingCustomers->retrieve((string) $this->airwallex_id);
    }

    /**
     * Get the name that should be synced to Airwallex.
     */
    public function airwallexName(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * Get the email address that should be synced to Airwallex.
     */
    public function airwallexEmail(): ?string
    {
        return $this->email ?? null;
    }

    /**
     * Get the address that should be synced to Airwallex.
     *
     * @return array<string, string>
     */
    public function airwallexAddress(): array
    {
        return [];

        // return [
        //     'city' => 'Kuala Lumpur',
        //     'country_code' => 'MY',
        //     'postcode' => '50450',
        //     'street' => '1 Jalan Ampang',
        // ];
    }

    /**
     * Get the metadata that should be synced to Airwallex.
     *
     * @return array<string, string>
     */
    public function airwallexMetadata(): array
    {
        return [];
    }

    /**
     * Sync the customer's information to Airwallex.
     */
    public function syncAirwallexCustomerDetails(): AirwallexObject
    {
        return $this->updateAirwallexCustomer(array_filter([
            'name' => $this->airwallexName(),
            'email' => $this->airwallexEmail(),
            'address' => $this->airwallexAddress(),
            'metadata' => $this->airwallexMetadata(),
        ]));
    }

    /**
     * Get the Airwallex supported currency used by the customer.
     */
    public function preferredCurrency(): string
    {
        return (string) config('billing.currency');
    }
}
