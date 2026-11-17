<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

final class BillingCheckouts extends Resource
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function create(array $params): AirwallexObject
    {
        return $this->client->request('POST', 'billing_checkouts/create', $params);
    }

    public function retrieve(string $id): AirwallexObject
    {
        return $this->client->request('GET', "billing_checkouts/{$id}");
    }

    public function cancel(string $id): AirwallexObject
    {
        return $this->client->request('POST', "billing_checkouts/{$id}/cancel");
    }
}
