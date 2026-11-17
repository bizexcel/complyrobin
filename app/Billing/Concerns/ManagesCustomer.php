<?php

namespace App\Billing\Concerns;

use App\Billing\Exceptions\InvalidCustomer;

/**
 * @property string|null $airwallex_id
 */
trait ManagesCustomer
{
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
}
