<?php

namespace App\Filament\Admin;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use Filament\Facades\Filament;

/**
 * The signed-in staff member an Action runs as, from a staff panel page or table action. 403 if there is none.
 */
final class Actor
{
    public static function current(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Whether the signed-in staff member is an Admin, the only staff role that may change anything.
     */
    public static function isAdmin(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->platform_role === PlatformRole::Admin;
    }
}
