<?php

namespace App\Core\People\Policies;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;

/**
 * Every member may see the organisation's people; changing them needs `people.manage`.
 * Only reached inside an organisation, where the model's scope already limits records to it.
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Person $person): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Ability::ManagePeople->value);
    }

    public function update(User $user, Person $person): bool
    {
        return $user->can(Ability::ManagePeople->value);
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->can(Ability::ManagePeople->value);
    }
}
