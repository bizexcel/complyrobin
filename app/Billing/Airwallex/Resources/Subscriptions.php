<?php

namespace App\Billing\Airwallex\Resources;

use App\Billing\Airwallex\AirwallexObject;

final class Subscriptions extends Resource
{
    public function retrieve(string $id): AirwallexObject
    {
        return $this->client->request('GET', "subscriptions/{$id}");
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function update(string $id, array $params): AirwallexObject
    {
        return $this->client->request('POST', "subscriptions/{$id}/update", $params);
    }

    /**
     * @param  array<string, mixed>  $params  `proration_behavior`: ALL, PRORATED or NONE
     */
    public function cancel(string $id, array $params = []): AirwallexObject
    {
        return $this->client->request('POST', "subscriptions/{$id}/cancel", $params);
    }

    public function items(string $id): AirwallexObject
    {
        return $this->client->request('GET', "subscriptions/{$id}/items");
    }

    /**
     * @param  array<string, mixed>  $params  e.g. `billing_customer_id`
     */
    public function all(array $params = []): AirwallexObject
    {
        return $this->client->request('GET', 'subscriptions', $params);
    }
}
