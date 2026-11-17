<?php

namespace App\Billing\Exceptions;

use App\Billing\Airwallex\AirwallexObject;
use Exception;
use Illuminate\Database\Eloquent\Model;

final class InvalidInvoice extends Exception
{
    /**
     * Create a new InvalidInvoice instance.
     */
    public static function invalidOwner(AirwallexObject $invoice, Model $owner): self
    {
        return new self("The invoice `{$invoice->id}` does not belong to this customer `{$owner->getAttribute('airwallex_id')}`.");
    }
}
