<?php

namespace App\Core\Tenancy\Events;

/**
 * The current organisation changed (or was cleared). Lets other parts of Core follow the tenant, e.g. spatie
 * permission's team and cache key.
 */
final readonly class TenantChanged
{
    public function __construct(public ?string $organisationId) {}
}
