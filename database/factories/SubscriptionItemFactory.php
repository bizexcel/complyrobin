<?php

namespace Database\Factories;

use App\Billing\Subscription;
use App\Billing\SubscriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Laravel Cashier's subscription item factory. Create inside `Tenancy::run($organisation, …)`.
 *
 * @extends Factory<SubscriptionItem>
 */
class SubscriptionItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SubscriptionItem>
     */
    #[\Override]
    protected $model = SubscriptionItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'airwallex_id' => 'si_'.Str::random(40),
            'airwallex_product' => 'prod_'.Str::random(40),
            'airwallex_price' => 'price_'.Str::random(40),
            'quantity' => null,
        ];
    }
}
