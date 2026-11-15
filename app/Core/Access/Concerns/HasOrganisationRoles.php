<?php

namespace App\Core\Access\Concerns;

use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * spatie's HasRoles, safe across organisations: spatie keeps a user's loaded roles and permissions on the model, so
 * after the current organisation changes (e.g. a job looping over organisations) it would keep answering for the
 * previous one. Loaded roles and permissions are dropped when they were loaded for another organisation.
 */
trait HasOrganisationRoles
{
    use HasRoles;

    /** @var array<string, string|int|null> */
    private array $organisationOfLoadedRoles = [];

    /**
     * @param  string  $key
     */
    #[\Override]
    public function getRelationValue($key): mixed
    {
        if ($key !== 'roles' && $key !== 'permissions') {
            return parent::getRelationValue($key);
        }

        $organisation = app(PermissionRegistrar::class)->getPermissionsTeamId();

        if ($this->relationLoaded($key) && ($this->organisationOfLoadedRoles[$key] ?? null) !== $organisation) {
            $this->unsetRelation($key);
        }

        $this->organisationOfLoadedRoles[$key] = $organisation;

        return parent::getRelationValue($key);
    }
}
