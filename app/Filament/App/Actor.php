<?php

namespace App\Filament\App;

use App\Core\Identity\Models\User;
use Filament\Facades\Filament;

/**
 * The signed-in user an Action runs as, from a page or table action. 403 if there is none.
 */
final class Actor
{
    public static function current(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
