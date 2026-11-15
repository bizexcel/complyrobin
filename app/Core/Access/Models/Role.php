<?php

namespace App\Core\Access\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role inside one organisation (spatie's team).
 *
 * @property string $organisation_id
 */
#[Fillable(['name', 'guard_name'])]
class Role extends SpatieRole
{
    use BelongsToOrganisation, HasUuids, LogsTenantActivity;

    /**
     * spatie doesn't put the team on role_has_permissions; the table requires it, so links stay inside the organisation.
     * Eager loading calls this on a blank role, so fall back to the current organisation (row-level security limits
     * the rows either way).
     *
     * @return BelongsToMany<Permission, $this>
     */
    #[\Override]
    public function permissions(): BelongsToMany
    {
        $relation = parent::permissions();
        $organisationId = $this->getAttribute('organisation_id') ?? (Tenancy::check() ? Tenancy::id() : null);

        return $organisationId === null ? $relation : $relation->withPivotValue('organisation_id', $organisationId);
    }
}
