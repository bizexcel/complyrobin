<?php

namespace App\Core\Access;

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Members of the current organisation.
 */
final class Members
{
    /**
     * Members holding any of the roles in the current organisation.
     *
     * @param  list<SystemRole>  $roles
     * @return Collection<int, User>
     */
    public static function withRoles(array $roles): Collection
    {
        return Organisation::query()->findOrFail(Tenancy::id())->users()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', array_map(fn (SystemRole $role): string => $role->value, $roles)))
            ->orderBy('name')
            ->get();
    }
}
