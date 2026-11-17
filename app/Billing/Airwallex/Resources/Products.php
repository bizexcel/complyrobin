<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

/**
 * Products are made once in the Airwallex dashboard (see billing.md); this only reads them.
 */
final class Products extends Resource
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function all(array $params = []): AirwallexObject
    {
        return $this->client->request('GET', 'products', $params);
    }
}
