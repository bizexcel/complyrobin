<?php

namespace App\Core\Organisations;

/**
 * Words an organisation slug may never be, because they are (or will be) top-level paths of the app.
 */
final class ReservedSlugs
{
    /** @var list<string> */
    public const array ALL = [
        'admin', 'api', 'app', 'www', 'login', 'logout', 'register', 'billing', 'settings', 'livewire', 'filament',
        'storage', 'up', 'health', 'airwallex', 'two-factor', 'password', 'email', 'invitations', 'docs', 'help',
        'support', 'status',
    ];

    public static function contains(string $slug): bool
    {
        return in_array($slug, self::ALL, true);
    }
}
