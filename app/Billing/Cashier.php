<?php

namespace App\Billing;

use App\Billing\Airwallex\AirwallexClient;
use App\Core\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Number;

/**
 * Laravel Cashier's static helpers, for Airwallex. The settings below are application configuration: they are set once
 * while the application boots (in a service provider), never per request or per job.
 */
final class Cashier
{
    /**
     * Indicates if Cashier routes will be registered.
     *
     * @var bool
     */
    public static $registersRoutes = true;

    /**
     * Indicates if Cashier will mark past due subscriptions as inactive.
     *
     * @var bool
     */
    public static $deactivatePastDue = true;

    /**
     * Indicates if Cashier will mark incomplete subscriptions as inactive.
     *
     * @var bool
     */
    public static $deactivateIncomplete = true;

    /**
     * The default customer model class name.
     *
     * @var class-string<Model>
     */
    public static $customerModel = Organisation::class;

    /**
     * The subscription model class name.
     *
     * @var class-string<Subscription>
     */
    public static $subscriptionModel = Subscription::class;

    /**
     * The subscription item model class name.
     *
     * @var class-string<SubscriptionItem>
     */
    public static $subscriptionItemModel = SubscriptionItem::class;

    /**
     * Get the Airwallex API client.
     *
     * @param  array{client_id?: string|null, api_key?: string|null, env?: string|null, api_version?: string|null}  $options
     */
    public static function airwallex(array $options = []): AirwallexClient
    {
        return new AirwallexClient(array_merge([
            'client_id' => config('billing.client_id'),
            'api_key' => config('billing.api_key'),
            'env' => config('billing.env'),
            'api_version' => config('billing.api_version'),
        ], $options));
    }

    /**
     * Format the given amount into a displayable currency. Airwallex amounts are in major units (49 is MYR 49.00).
     */
    public static function formatAmount(int|float $amount, ?string $currency = null, ?string $locale = null): string
    {
        return (string) Number::currency($amount, strtoupper($currency ?? (string) config('billing.currency')), $locale ?? (string) config('billing.currency_locale'));
    }

    /**
     * Get the customer instance by its Airwallex ID.
     */
    public static function findBillable(?string $airwallexId): ?Model
    {
        $model = self::$customerModel;

        $builder = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model))) {
            $builder->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $airwallexId ? $builder->where('airwallex_id', $airwallexId)->first() : null;
    }

    /**
     * Configure Cashier to not register its routes.
     */
    public static function ignoreRoutes(): static
    {
        self::$registersRoutes = false;

        return new self;
    }

    /**
     * Configure Cashier to maintain past due subscriptions as active.
     */
    public static function keepPastDueSubscriptionsActive(): static
    {
        self::$deactivatePastDue = false;

        return new self;
    }

    /**
     * Configure Cashier to maintain incomplete subscriptions as active.
     */
    public static function keepIncompleteSubscriptionsActive(): static
    {
        self::$deactivateIncomplete = false;

        return new self;
    }

    /**
     * Set the customer model class name.
     *
     * @param  class-string<Model>  $customerModel
     */
    public static function useCustomerModel(string $customerModel): void
    {
        self::$customerModel = $customerModel;
    }

    /**
     * Set the subscription model class name.
     *
     * @param  class-string<Subscription>  $subscriptionModel
     */
    public static function useSubscriptionModel(string $subscriptionModel): void
    {
        self::$subscriptionModel = $subscriptionModel;
    }

    /**
     * Set the subscription item model class name.
     *
     * @param  class-string<SubscriptionItem>  $subscriptionItemModel
     */
    public static function useSubscriptionItemModel(string $subscriptionItemModel): void
    {
        self::$subscriptionItemModel = $subscriptionItemModel;
    }
}
