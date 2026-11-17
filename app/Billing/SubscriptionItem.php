<?php

namespace App\Billing;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use Database\Factories\SubscriptionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $subscription_id
 * @property string $airwallex_id
 * @property string $airwallex_product
 * @property string $airwallex_price
 * @property int|null $quantity
 * @property Subscription|null $subscription
 */
#[Fillable(['subscription_id', 'airwallex_id', 'airwallex_product', 'airwallex_price', 'quantity'])]
#[UseFactory(SubscriptionItemFactory::class)]
class SubscriptionItem extends Model
{
    /** @use HasFactory<SubscriptionItemFactory> */
    use BelongsToOrganisation, HasFactory, HasUuids, LogsTenantActivity;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    #[\Override]
    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the subscription that the item belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        $model = Cashier::$subscriptionModel;

        return $this->belongsTo($model, (new $model)->getForeignKey());
    }
}
