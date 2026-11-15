<?php

namespace App\Modules\Credentials\Enums;

/**
 * Worked out from the dates, never stored.
 */
enum CredentialStatus: string
{
    case Active = 'active';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::DueSoon => 'Due soon',
            self::Overdue => 'Overdue',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }
}
