<?php

namespace App\Modules\Credentials\Policies;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;
use App\Modules\Credentials\Models\Credential;

/**
 * Every member may see the register; changing it needs `credentials.manage`. Who may, only: rules about the
 * credential's state (revoked…) live in the Actions, because Owners pass every gate check before policies run.
 */
class CredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Credential $credential): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }

    public function update(User $user, Credential $credential): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }

    public function revoke(User $user, Credential $credential): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }

    /**
     * Anyone who rotates credentials, and the credential's own owner and backup owner.
     */
    public function rotate(User $user, Credential $credential): bool
    {
        if ($user->can(Ability::RotateCredentials->value)) {
            return true;
        }

        $responsible = Person::query()->whereKey(array_filter([$credential->owner_id, $credential->backup_owner_id]))->pluck('user_id');

        return $responsible->contains($user->getKey());
    }

    public function delete(User $user, Credential $credential): bool
    {
        return $user->can(Ability::ManageCredentials->value);
    }
}
