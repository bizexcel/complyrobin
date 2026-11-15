<?php

namespace App\Core\Identity\Enums;

/**
 * ComplyRobin staff roles. Customers have no platform role; their roles are per organisation.
 */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Support = 'support';
}
