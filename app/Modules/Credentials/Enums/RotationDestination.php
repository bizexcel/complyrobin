<?php

namespace App\Modules\Credentials\Enums;

/**
 * Where a rotation runner puts the new key.
 */
enum RotationDestination: string
{
    case LaravelCloud = 'laravel_cloud';

    public function label(): string
    {
        return match ($this) {
            self::LaravelCloud => 'Laravel Cloud',
        };
    }
}
