<?php

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Models\Runner;
use Illuminate\Support\Str;

/**
 * Runner tokens: `crr_` and 60 random characters, shown once. Only their sha-256 is stored.
 */
final class RunnerTokens
{
    public const string PREFIX = 'crr_';

    public static function generate(): string
    {
        return self::PREFIX.Str::random(60);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The current organisation's active runner with this token, or null.
     */
    public static function find(string $token): ?Runner
    {
        if (preg_match('/^crr_[A-Za-z0-9]{60}$/', $token) !== 1) {
            return null;
        }

        return Runner::query()->where('token', self::hash($token))->whereNull('revoked_at')->first();
    }
}
