<?php

namespace App\Billing;

use App\Billing\Airwallex\AirwallexSubscription;
use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Carbon\Carbon;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A root organisation's subscription to one module; the type is the module key.
 *
 * @property string $type
 * @property string $airwallex_id
 * @property string $airwallex_status
 * @property string|null $airwallex_price
 * @property int|null $quantity
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property-read Collection<int, SubscriptionItem> $items
 * @property Organisation $owner
 */
#[Fillable(['type', 'airwallex_id', 'airwallex_status', 'airwallex_price', 'quantity', 'trial_ends_at', 'ends_at'])]
#[UseFactory(SubscriptionFactory::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, LogsTenantActivity;

    /**
     * The relations to eager load on every query.
     *
     * @var list<string>
     */
    #[\Override]
    protected $with = ['items'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    #[\Override]
    protected $casts = [
        'ends_at' => 'datetime',
        'quantity' => 'integer',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Get the user that owns the subscription.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->owner();
    }

    /**
     * Get the model related to the subscription.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $model = Cashier::$customerModel;

        return $this->belongsTo($model, (new $model)->getForeignKey());
    }

    /**
     * Get the subscription items related to the subscription.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Cashier::$subscriptionItemModel);
    }

    /**
     * Determine if the subscription has multiple prices.
     */
    public function hasMultiplePrices(): bool
    {
        return is_null($this->airwallex_price);
    }

    /**
     * Determine if the subscription has a single price.
     */
    public function hasSinglePrice(): bool
    {
        return ! $this->hasMultiplePrices();
    }

    /**
     * Determine if the subscription has a specific product.
     */
    public function hasProduct(string $product): bool
    {
        return $this->items->contains(fn (SubscriptionItem $item): bool => $item->airwallex_product === $product);
    }

    /**
     * Determine if the subscription has a specific price.
     */
    public function hasPrice(string $price): bool
    {
        if ($this->hasMultiplePrices()) {
            return $this->items->contains(fn (SubscriptionItem $item): bool => $item->airwallex_price === $price);
        }

        return $this->airwallex_price === $price;
    }

    /**
     * Get the subscription item for the given price.
     *
     *
     * @throws ModelNotFoundException
     */
    public function findItemOrFail(string $price): SubscriptionItem
    {
        return $this->items()->where('airwallex_price', $price)->firstOrFail();
    }

    /**
     * Determine if the subscription is active, on trial, or within its grace period.
     */
    public function valid(): bool
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is incomplete.
     */
    public function incomplete(): bool
    {
        return $this->airwallex_status === AirwallexSubscription::STATUS_PENDING;
    }

    /**
     * Filter query by incomplete.
     *
     * @param  Builder<self>  $query
     */
    public function scopeIncomplete(Builder $query): void
    {
        $query->where('airwallex_status', AirwallexSubscription::STATUS_PENDING);
    }

    /**
     * Determine if the subscription is past due.
     */
    public function pastDue(): bool
    {
        return $this->airwallex_status === AirwallexSubscription::STATUS_UNPAID;
    }

    /**
     * Filter query by past due.
     *
     * @param  Builder<self>  $query
     */
    public function scopePastDue(Builder $query): void
    {
        $query->where('airwallex_status', AirwallexSubscription::STATUS_UNPAID);
    }

    /**
     * Determine if the subscription is active.
     */
    public function active(): bool
    {
        return ! $this->ended() &&
            (! Cashier::$deactivateIncomplete || $this->airwallex_status !== AirwallexSubscription::STATUS_PENDING) &&
            (! Cashier::$deactivatePastDue || $this->airwallex_status !== AirwallexSubscription::STATUS_UNPAID);
    }

    /**
     * Filter query by active.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('ends_at')
                ->orWhere(function (Builder $query): void {
                    $query->onGracePeriod();
                });
        });

        if (Cashier::$deactivatePastDue) {
            $query->where('airwallex_status', '!=', AirwallexSubscription::STATUS_UNPAID);
        }

        if (Cashier::$deactivateIncomplete) {
            $query->where('airwallex_status', '!=', AirwallexSubscription::STATUS_PENDING);
        }
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     */
    public function recurring(): bool
    {
        return ! $this->onTrial() && ! $this->canceled();
    }

    /**
     * Filter query by recurring.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->notOnTrial()->notCanceled();
    }

    /**
     * Determine if the subscription is no longer active.
     */
    public function canceled(): bool
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Filter query by canceled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCanceled(Builder $query): void
    {
        $query->whereNotNull('ends_at');
    }

    /**
     * Filter query by not canceled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotCanceled(Builder $query): void
    {
        $query->whereNull('ends_at');
    }

    /**
     * Determine if the subscription has ended and the grace period has expired.
     */
    public function ended(): bool
    {
        return $this->canceled() && ! $this->onGracePeriod();
    }

    /**
     * Filter query by ended.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->canceled()->notOnGracePeriod();
    }

    /**
     * Determine if the subscription is within its trial period.
     */
    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Filter query by on trial.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * Determine if the subscription's trial has expired.
     */
    public function hasExpiredTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    /**
     * Filter query by expired trial.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpiredTrial(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', Carbon::now());
    }

    /**
     * Filter query by not on trial.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotOnTrial(Builder $query): void
    {
        $query->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', Carbon::now());
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     */
    public function onGracePeriod(): bool
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Filter query by on grace period.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnGracePeriod(Builder $query): void
    {
        $query->whereNotNull('ends_at')->where('ends_at', '>', Carbon::now());
    }

    /**
     * Filter query by not on grace period.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotOnGracePeriod(Builder $query): void
    {
        $query->whereNull('ends_at')->orWhere('ends_at', '<=', Carbon::now());
    }

    /**
     * Mark the subscription as canceled.
     *
     *
     * @internal
     */
    public function markAsCanceled(): void
    {
        $this->fill([
            'airwallex_status' => AirwallexSubscription::STATUS_CANCELLED,
            'ends_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Make sure a price argument is provided when the subscription is a subscription with multiple prices.
     *
     *
     * @throws InvalidArgumentException
     */
    public function guardAgainstMultiplePrices(): void
    {
        if ($this->hasMultiplePrices()) {
            throw new InvalidArgumentException(
                'This method requires a price argument since the subscription has multiple prices.'
            );
        }
    }
}
