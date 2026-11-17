<?php

namespace App\Billing\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;

final class CustomerAlreadyCreated extends Exception
{
    /**
     * Create a new CustomerAlreadyCreated instance.
     */
    public static function exists(Model $owner): self
    {
        return new self(class_basename($owner)." is already an Airwallex customer with ID {$owner->getAttribute('airwallex_id')}.");
    }
}
