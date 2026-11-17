<?php

namespace App\Billing\Airwallex;

use Illuminate\Support\Fluent;

/**
 * An Airwallex API object, as Stripe's SDK returns a `StripeObject`: read its fields as properties (`$customer->id`)
 * or array keys. Nested objects stay arrays.
 *
 * @extends Fluent<string, mixed>
 */
final class AirwallexObject extends Fluent {}
