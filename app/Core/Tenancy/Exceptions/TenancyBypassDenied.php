<?php

namespace App\Core\Tenancy\Exceptions;

use RuntimeException;

class TenancyBypassDenied extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Tenancy bypass ({$reason}) is only allowed for platform requests and console commands.");
    }

    public static function insideTransaction(string $reason): self
    {
        return new self("Tenancy bypass ({$reason}) cannot run inside an open tenant transaction: commit first or do the platform work outside it.");
    }
}
