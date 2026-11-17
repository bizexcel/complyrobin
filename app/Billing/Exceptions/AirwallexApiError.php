<?php

namespace App\Billing\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * Stripe's ApiErrorException, for Airwallex: the API refused a request. Airwallex answers with
 * `{code, message, source?, details?}`, for example `validation_error` with the field in `source`.
 */
final class AirwallexApiError extends Exception
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $airwallexCode = null,
        public readonly ?string $source = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('code');
        $message = $response->json('message');
        $source = $response->json('source');

        return new self(
            is_string($message) && $message !== '' ? $message : "Airwallex answered HTTP {$response->status()}.",
            $response->status(),
            is_string($code) ? $code : null,
            is_string($source) && $source !== '' ? $source : null,
        );
    }
}
