<?php

namespace App\Billing\Airwallex;

use App\Billing\Exceptions\InvalidWebhookSignature;

/**
 * Stripe's `WebhookSignature`, for Airwallex. Airwallex signs the `x-timestamp` header (milliseconds since the epoch)
 * followed by the raw body with HMAC-SHA256 and the endpoint's secret, and sends the hex digest as `x-signature`.
 */
final class WebhookSignature
{
    /**
     * Verifies the signature sent by Airwallex. Throws an InvalidWebhookSignature exception if the verification fails
     * for any reason.
     *
     * @param  int  $tolerance  the most a call's timestamp may differ from now, in seconds
     *
     * @throws InvalidWebhookSignature
     */
    public static function verifyHeader(string $payload, ?string $timestamp, ?string $signature, ?string $secret, int $tolerance): true
    {
        if ($secret === null || $secret === '') {
            throw InvalidWebhookSignature::noSecret();
        }

        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            throw InvalidWebhookSignature::missingHeaders();
        }

        if (! hash_equals(self::computeSignature($timestamp, $payload, $secret), $signature)) {
            throw InvalidWebhookSignature::noMatch();
        }

        if (abs(now()->getTimestampMs() - (int) $timestamp) > $tolerance * 1000) {
            throw InvalidWebhookSignature::outsideTolerance();
        }

        return true;
    }

    /**
     * Computes the signature for a given timestamp and payload.
     */
    public static function computeSignature(string $timestamp, string $payload, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.$payload, $secret);
    }
}
