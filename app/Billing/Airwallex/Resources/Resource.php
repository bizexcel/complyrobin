<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexClient;

/**
 * One kind of Airwallex Billing object, as Stripe's SDK has a service per resource (`$stripe->customers->create()`).
 */
abstract class Resource
{
    public function __construct(protected readonly AirwallexClient $client) {}
}
