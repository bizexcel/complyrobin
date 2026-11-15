<?php

namespace App\Core\Access\Models;

use App\Core\Audit\Concerns\LogsTenantActivity;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
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
     *
     * @return BelongsToMany<Permission, $this>
     */
    #[\Override]
    public function permissions(): BelongsToMany
    {
        return parent::permissions()->withPivotValue('organisation_id', $this->organisation_id);
    }
}
