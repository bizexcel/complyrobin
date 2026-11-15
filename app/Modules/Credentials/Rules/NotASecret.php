<?php

namespace App\Modules\Credentials\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses text that looks like an actual secret: well-known key formats, private keys, JWTs and long random strings.
 * ComplyRobin records information about credentials, never the credential itself.
 */
final class NotASecret implements ValidationRule
{
    /** @var list<string> */
    private const array PATTERNS = [
        '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}/',        // GitHub tokens
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',                     // AWS access key ids
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/',      // Stripe keys
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',                   // Slack tokens
        '/\bsk-(?:ant-|proj-)?[A-Za-z0-9_-]{20,}/',            // OpenAI / Anthropic keys
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',                // PEM private keys
        '/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\./',    // JWTs
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (array_any(self::PATTERNS, fn (string $pattern): bool => preg_match($pattern, $value) === 1) || $this->hasRandomLookingRun($value)) {
            $fail('This looks like a real key or password. Record where it is used, not the secret itself.');
        }
    }

    /**
     * A run of 32+ key characters mixing upper case, lower case and digits, as in base64 or hex secrets.
     */
    private function hasRandomLookingRun(string $value): bool
    {
        preg_match_all('/[A-Za-z0-9+\/=_-]{32,}/', $value, $matches);

        return array_any($matches[0], fn (string $run): bool => preg_match('/[a-z]/', $run) === 1
            && preg_match('/[A-Z]/', $run) === 1
            && preg_match('/\d/', $run) === 1);
    }
}
