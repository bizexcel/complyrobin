<?php

namespace Database\Factories;

use App\Billing\Airwallex\AirwallexSubscription;
use App\Billing\Subscription;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Laravel Cashier's subscription factory. Create inside `Tenancy::run($organisation, …)`, which sets the owner.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Subscription>
     */
    #[\Override]
    protected $model = Subscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'credentials',
            'airwallex_id' => 'sub_'.Str::random(40),
            'airwallex_status' => AirwallexSubscription::STATUS_ACTIVE,
            'airwallex_price' => null,
            'quantity' => null,
            'trial_ends_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * Add a price identifier to the model.
     */
    public function withPrice(string $price): static
    {
        return $this->state([
            'airwallex_price' => $price,
        ]);
    }

    /**
     * Mark the subscription as active.
     */
    public function active(): static
    {
        return $this->state([
            'airwallex_status' => AirwallexSubscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * Mark the subscription as being within a trial period.
     */
    public function trialing(?DateTimeInterface $trialEndsAt = null): static
    {
        return $this->state([
            'airwallex_status' => AirwallexSubscription::STATUS_IN_TRIAL,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }

    /**
     * Mark the subscription as canceled.
     */
    public function canceled(): static
    {
        return $this->state([
            'airwallex_status' => AirwallexSubscription::STATUS_CANCELLED,
            'ends_at' => now(),
        ]);
    }

    /**
     * Mark the subscription as incomplete.
     */
    public function incomplete(): static
    {
        return $this->state([
            'airwallex_status' => AirwallexSubscription::STATUS_PENDING,
        ]);
    }

    /**
     * Mark the subscription as being past the due date.
     */
    public function pastDue(): static
    {
        return $this->state([
            'airwallex_status' => AirwallexSubscription::STATUS_UNPAID,
        ]);
    }
}
