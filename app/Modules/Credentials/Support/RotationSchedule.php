<?php

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Enums\CredentialStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Due dates in the organisation's own days: something due later today, Kuala Lumpur time, is due today there even
 * while it is still yesterday in UTC.
 */
final class RotationSchedule
{
    /**
     * Whole days from today to the due date in the time zone: 0 is due today, negative is overdue.
     */
    public static function daysUntilDue(CarbonInterface $dueAt, CarbonInterface $now, string $timezone): int
    {
        $today = CarbonImmutable::instance($now)->setTimezone($timezone)->startOfDay();
        $dueDay = CarbonImmutable::instance($dueAt)->setTimezone($timezone)->startOfDay();

        return (int) $today->diffInDays($dueDay, false);
    }

    /**
     * Midnight starting the organisation's day, some days from today, as a UTC instant. Compare stored timestamps with
     * this, never with a local-time Carbon: query bindings drop the time zone, so local midnight would be read as UTC.
     */
    public static function startOfDay(CarbonInterface $now, string $timezone, int $daysFromToday = 0): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($timezone)->startOfDay()->addDays($daysFromToday)->utc();
    }

    public static function status(
        ?CarbonInterface $dueAt,
        ?CarbonInterface $expiresAt,
        ?CarbonInterface $revokedAt,
        CarbonInterface $now,
        string $timezone,
        int $dueSoonDays,
    ): CredentialStatus {
        if ($revokedAt instanceof CarbonInterface) {
            return CredentialStatus::Revoked;
        }

        if ($expiresAt instanceof CarbonInterface && $expiresAt->lessThanOrEqualTo($now)) {
            return CredentialStatus::Expired;
        }

        if (! $dueAt instanceof CarbonInterface) {
            return CredentialStatus::Active;
        }

        $days = self::daysUntilDue($dueAt, $now, $timezone);

        return match (true) {
            $days < 0 => CredentialStatus::Overdue,
            $days <= $dueSoonDays => CredentialStatus::DueSoon,
            default => CredentialStatus::Active,
        };
    }
}
