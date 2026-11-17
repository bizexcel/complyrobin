<?php

namespace App\Billing\Airwallex;

/**
 * The statuses an Airwallex Billing subscription can have, as Stripe's SDK names them on `Stripe\Subscription`.
 * To confirm against recorded demo payloads in the sandbox spike before checkout goes live.
 */
final class AirwallexSubscription
{
    /**
     * Created, waiting for the first payment (Stripe's "incomplete").
     */
    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_IN_TRIAL = 'IN_TRIAL';

    public const string STATUS_ACTIVE = 'ACTIVE';

    public const string STATUS_PAST_DUE = 'PAST_DUE';

    public const string STATUS_UNPAID = 'UNPAID';

    public const string STATUS_CANCELLED = 'CANCELLED';
}
