<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

class TenantMismatch extends RuntimeException
{
    public static function for(string $model, string $given, string $current): self
    {
        return new self("Refusing to save {$model} for organisation {$given} while the current organisation is {$current}.");
    }
}
