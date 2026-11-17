<?php

namespace App\Billing\Concerns;

/**
 * Laravel Cashier's Prorates, with Airwallex's `proration_behavior` values: PRORATED for Stripe's
 * "create_prorations", NONE for "none".
 */
trait Prorates
{
    /**
     * Indicates if the price change should be prorated.
     */
    protected string $prorationBehavior = 'PRORATED';

    /**
     * Indicate that the price change should not be prorated.
     *
     * @return $this
     */
    public function noProrate(): static
    {
        $this->prorationBehavior = 'NONE';

        return $this;
    }

    /**
     * Indicate that the price change should be prorated.
     *
     * @return $this
     */
    public function prorate(): static
    {
        $this->prorationBehavior = 'PRORATED';

        return $this;
    }

    /**
     * Set the prorating behavior.
     *
     * @return $this
     */
    public function setProrationBehavior(string $prorationBehavior): static
    {
        $this->prorationBehavior = $prorationBehavior;

        return $this;
    }

    /**
     * Determine the prorating behavior when updating the subscription.
     */
    public function prorateBehavior(): string
    {
        return $this->prorationBehavior;
    }
}
