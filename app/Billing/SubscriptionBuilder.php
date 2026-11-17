<?php

namespace App\Billing;

use App\Core\Organisations\Models\Organisation;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;

/**
 * Laravel Cashier's SubscriptionBuilder, for Airwallex. Subscriptions start only through `checkout()`: one created
 * directly with Airwallex's `subscriptions/create` is ACTIVE before it is paid. The local subscription row is written
 * by the webhook once the checkout completes, as in Cashier.
 */
class SubscriptionBuilder
{
    use Conditionable;

    /**
     * The prices the customer is being subscribed to.
     *
     * @var array<string, array{price: string, quantity?: int}>
     */
    protected array $items = [];

    /**
     * The date and time the trial will expire.
     */
    protected ?CarbonInterface $trialExpires = null;

    /**
     * Indicates that the trial should end immediately.
     */
    protected bool $skipTrial = false;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array<string, string>
     */
    protected array $metadata = [];

    /**
     * Create a new subscription builder instance.
     *
     * @param  Organisation  $owner
     * @param  string|string[]  $prices
     */
    public function __construct(protected Model $owner, protected string $type, string|array $prices = [])
    {
        foreach ((array) $prices as $price) {
            $this->price($price);
        }
    }

    /**
     * Set a price on the subscription builder.
     *
     * @return $this
     */
    public function price(string $price, ?int $quantity = 1): static
    {
        $options = ['price' => $price];

        if (! is_null($quantity)) {
            $options['quantity'] = $quantity;
        }

        $this->items[$price] = $options;

        return $this;
    }

    /**
     * Specify the quantity of a subscription item.
     *
     * @return $this
     */
    public function quantity(?int $quantity, ?string $price = null): static
    {
        if (is_null($price)) {
            if ($this->items === []) {
                throw new InvalidArgumentException('No price specified for quantity update.');
            }

            if (count($this->items) > 1) {
                throw new InvalidArgumentException('Price is required when creating subscriptions with multiple prices.');
            }

            $price = Arr::first($this->items)['price'];
        }

        return $this->price($price, $quantity);
    }

    /**
     * Specify the number of days of the trial.
     *
     * @return $this
     */
    public function trialDays(int $trialDays): static
    {
        $this->trialExpires = Carbon::now()->addDays($trialDays);

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @return $this
     */
    public function trialUntil(CarbonInterface $trialUntil): static
    {
        $this->trialExpires = $trialUntil;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial(): static
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param  array<string, string>  $metadata
     * @return $this
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Begin a new Checkout Session.
     *
     * Airwallex needs a trial of at least a day, and keeps a checkout open for 30 days; Stripe's 48-hour minimum
     * doesn't apply.
     *
     * @param  array<string, mixed>  $sessionOptions
     * @param  array<string, mixed>  $customerOptions
     */
    public function checkout(array $sessionOptions = [], array $customerOptions = []): Checkout
    {
        if ($this->items === []) {
            throw new Exception('At least one price is required when starting subscriptions.');
        }

        $trialEnd = ! $this->skipTrial && $this->trialExpires instanceof CarbonInterface ? $this->trialExpires : null;

        $metadata = array_merge($this->owner->airwallexMetadata(), $this->metadata, [
            'type' => $this->type,
        ]);

        $payload = [
            'line_items' => Collection::make($this->items)->map(fn (array $item): array => array_filter([
                'price_id' => $item['price'],
                'quantity' => $item['quantity'] ?? null,
            ], fn (mixed $value): bool => $value !== null))->values()->all(),
            'metadata' => $metadata,
            'subscription_data' => array_filter([
                'trial_ends_at' => $trialEnd?->copy()->utc()->format('Y-m-d\TH:i:sO'),
                'metadata' => $metadata,
            ]),
        ];

        return Checkout::create($this->owner, array_replace_recursive($payload, $sessionOptions), $customerOptions);
    }

    /**
     * Get the items set on the subscription builder.
     *
     * @return array<string, array{price: string, quantity?: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
