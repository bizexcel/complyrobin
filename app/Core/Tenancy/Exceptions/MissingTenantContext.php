<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public static function make(): self
    {
        return new self('No organisation is set. Wrap the work in Tenancy::run() or, for platform work, Tenancy::bypass().');
    }
}
