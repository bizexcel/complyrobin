<?php

namespace App\Billing\Concerns;

use App\Billing\Cashier;
use App\Billing\Subscription;
use App\Billing\SubscriptionBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property-read Collection<int, Subscription> $subscriptions
 */
trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     *
     * @param  string|string[]  $prices
     */
    public function newSubscription(string $type, string|array $prices = []): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $type, $prices);
    }

    /**
     * Determine if the Airwallex model is on trial.
     */
    public function onTrial(string $type = 'default', ?string $price = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->onTrial()) {
            return false;
        }

        return ! $price || $subscription->hasPrice($price);
    }

    /**
     * Determine if the Airwallex model's trial has ended.
     */
    public function hasExpiredTrial(string $type = 'default', ?string $price = null): bool
    {
        if (func_num_args() === 0 && $this->hasExpiredGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->hasExpiredTrial()) {
            return false;
        }

        return ! $price || $subscription->hasPrice($price);
    }

    /**
     * Determine if the Airwallex model is on a "generic" trial at the model level.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Filter the given query for generic trials.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnGenericTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * Determine if the Airwallex model's "generic" trial at the model level has expired.
     */
    public function hasExpiredGenericTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Filter the given query for expired generic trials.
     *
     * @param  Builder<self>  $query
     */
    public function scopeHasExpiredGenericTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', Carbon::now());
    }

    /**
     * Get the ending date of the trial.
     */
    public function trialEndsAt(string $type = 'default'): ?\Illuminate\Support\Carbon
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return $this->trial_ends_at;
        }

        if ($subscription = $this->subscription($type)) {
            return $subscription->trial_ends_at;
        }

        return $this->trial_ends_at;
    }

    /**
     * Determine if the Airwallex model has a given subscription.
     */
    public function subscribed(string $type = 'default', ?string $price = null): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return ! $price || $subscription->hasPrice($price);
    }

    /**
     * Get a subscription instance by $type.
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->subscriptions->where('type', $type)->first();
    }

    /**
     * Get all of the subscriptions for the Airwallex model.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Cashier::$subscriptionModel, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Determine if the Airwallex model is actively subscribed to one of the given products.
     *
     * @param  string|string[]  $products
     */
    public function subscribedToProduct(string|array $products, string $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return array_any((array) $products, fn ($product) => $subscription->hasProduct($product));
    }

    /**
     * Determine if the Airwallex model is actively subscribed to one of the given prices.
     *
     * @param  string|string[]  $prices
     * @param  string  $type
     */
    public function subscribedToPrice(string|array $prices, $type = 'default'): bool
    {
        $subscription = $this->subscription($type);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        return array_any((array) $prices, fn ($price) => $subscription->hasPrice($price));
    }

    /**
     * Determine if the customer has a valid subscription on the given product.
     */
    public function onProduct(string $product): bool
    {
        return ! is_null($this->subscriptions->first(fn (Subscription $subscription): bool => $subscription->valid() && $subscription->hasProduct($product)));
    }

    /**
     * Determine if the customer has a valid subscription on the given price.
     */
    public function onPrice(string $price): bool
    {
        return ! is_null($this->subscriptions->first(fn (Subscription $subscription): bool => $subscription->valid() && $subscription->hasPrice($price)));
    }
}
