<?php

namespace App\Billing\Exceptions;

use Exception;

/**
 * Stripe's SignatureVerificationException, for Airwallex: a webhook call that Airwallex did not sign, or signed too long ago.
 */
final class InvalidWebhookSignature extends Exception
{
    public static function noSecret(): static
    {
        return new self('No Airwallex webhook secret is configured.');
    }

    public static function missingHeaders(): static
    {
        return new self('Unable to extract timestamp and signature from header.');
    }

    public static function noMatch(): static
    {
        return new self('No signatures found matching the expected signature for payload.');
    }

    public static function outsideTolerance(): static
    {
        return new self('Timestamp outside the tolerance zone.');
    }
}
