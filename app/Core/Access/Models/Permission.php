<?php

namespace App\Core\Access\Models;

use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A global permission, defined in code by `App\Core\Access\Enums\Ability`. Only the seeder writes these.
 */
#[Fillable(['name', 'guard_name'])]
class Permission extends SpatiePermission
{
    use HasUuids, UsesTenancyConnection;
}
