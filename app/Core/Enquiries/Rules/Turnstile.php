<?php

namespace App\Core\Enquiries\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Cloudflare Turnstile token from the www contact form, checked with Cloudflare. Fails closed: with no secret key
 * configured, or when Cloudflare can't be reached, nothing gets through.
 */
final readonly class Turnstile implements ValidationRule
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private ?string $remoteIp = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('services.turnstile.secret_key');

        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail($this->message());

            return;
        }

        if (! is_string($secret) || $secret === '') {
            report(new RuntimeException('TURNSTILE_SECRET_KEY is not set, so every enquiry is refused.'));
            $fail($this->message());

            return;
        }

        try {
            $response = Http::asForm()->timeout(5)->withoutRedirecting()->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $value,
                'remoteip' => $this->remoteIp,
            ]));
        } catch (ConnectionException) {
            $fail($this->message());

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $fail($this->message());
        }
    }

    private function message(): string
    {
        return 'We couldn\'t confirm this came from a person. Reload the page and send it again.';
    }
}
