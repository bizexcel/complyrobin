<?php

namespace App\Core\Files;

/**
 * Where private files go: evidence, reports and uploads waiting to be imported. That is the default disk unless
 * MEDIA_DISK names another. On Laravel Cloud the default disk is the private bucket attached as the environment's
 * default, which Cloud configures after the config files load, so this is resolved at runtime.
 */
final class PrivateDisk
{
    public static function name(): string
    {
        return (string) (config('media-library.disk_name') ?: config('filesystems.default'));
    }
}
