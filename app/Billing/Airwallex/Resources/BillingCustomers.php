<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

final class BillingCustomers extends Resource
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function create(array $params): AirwallexObject
    {
        return $this->client->request('POST', 'billing_customers/create', $params);
    }

    public function retrieve(string $id): AirwallexObject
    {
        return $this->client->request('GET', "billing_customers/{$id}");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function update(string $id, array $params): AirwallexObject
    {
        return $this->client->request('POST', "billing_customers/{$id}/update", $params);
    }
}
