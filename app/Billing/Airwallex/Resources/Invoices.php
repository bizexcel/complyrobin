<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

final class Invoices extends Resource
{
    public function retrieve(string $id): AirwallexObject
    {
        return $this->client->request('GET', "invoices/{$id}");
    }

    /**
     * @param  array<string, mixed>  $params  e.g. `subscription_id`
     */
    public function all(array $params = []): AirwallexObject
    {
        return $this->client->request('GET', 'invoices', $params);
    }
}
