<?php

namespace App\Modules\Credentials\Policies;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Application;

/**
 * Every member may see the organisation's applications; changing them needs `credentials.manage`.
 */
class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Application $application): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }
}
