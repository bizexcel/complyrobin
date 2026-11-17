<?php

namespace App\Billing\Airwallex;

/**
 * The statuses an Airwallex Billing subscription can have, as Stripe's SDK names them on `Stripe\Subscription`.
 * Confirmed against the demo API (tests/Fixtures/airwallex). Airwallex has no "past due": an unpaid invoice moves the
 * subscription to UNPAID, which Cashier's past due maps to.
 */
final class AirwallexSubscription
{
    /**
     * Created, waiting for the first payment (Stripe's "incomplete").
     */
    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_IN_TRIAL = 'IN_TRIAL';

    public const string STATUS_ACTIVE = 'ACTIVE';

    /**
     * An invoice is due and unpaid (Stripe's "past_due").
     */
    public const string STATUS_UNPAID = 'UNPAID';

    public const string STATUS_CANCELLED = 'CANCELLED';
}
