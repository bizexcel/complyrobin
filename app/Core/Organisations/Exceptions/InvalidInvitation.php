<?php

namespace App\Core\Organisations\Exceptions;

use RuntimeException;

/**
 * The invitation link is unknown, expired or already used. Shown to the visitor as a plain 404.
 */
class InvalidInvitation extends RuntimeException
{
    public static function make(): self
    {
        return new self('This invitation is invalid, has expired or has already been used.');
    }
}
