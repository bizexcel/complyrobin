<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

/**
 * Prices are made once in the Airwallex dashboard (see billing.md); this only reads them.
 */
final class Prices extends Resource
{
    /**
     * @param  array<string, mixed>  $params  e.g. `product_id`, `active`, `currency`
     */
    public function all(array $params = []): AirwallexObject
    {
        return $this->client->request('GET', 'prices', $params);
    }
}
