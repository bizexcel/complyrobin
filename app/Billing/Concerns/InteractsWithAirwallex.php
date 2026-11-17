<?php

namespace App\Billing\Concerns;

use App\Billing\Airwallex\AirwallexClient;
use App\Billing\Cashier;

trait InteractsWithAirwallex
{
    /**
     * Get the Airwallex SDK client.
     *
     * @param  array{client_id?: string|null, api_key?: string|null, env?: string|null, api_version?: string|null}  $options
     */
    public static function airwallex(array $options = []): AirwallexClient
    {
        return Cashier::airwallex($options);
    }
}
