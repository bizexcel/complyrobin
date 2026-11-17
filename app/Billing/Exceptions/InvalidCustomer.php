<?php

namespace App\Billing\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;

final class InvalidCustomer extends Exception
{
    /**
     * Create a new InvalidCustomer instance.
     */
    public static function notYetCreated(Model $owner): static
    {
        return new self(class_basename($owner).' is not an Airwallex customer yet. See the createAsAirwallexCustomer method.');
    }
}
