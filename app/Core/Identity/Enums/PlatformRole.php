<?php

namespace App\Core\Identity\Enums;

/**
 * ComplyRobin staff roles. Customers have no platform role; their roles are per organisation.
 * Any staff role opens the staff panel. Only an Admin may change anything there: grant plans and manage staff.
 */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Support => 'Support',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Everything in the staff panel, including plans and staff',
            self::Support => 'Can look at everything in the staff panel, but not change it',
        };
    }
}
