<?php

namespace App\Core\Organisations\Policies;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;

class OrganisationPolicy
{
    /**
     * Change the organisation's settings (name, time zone, slug, reminder schedule).
     */
    public function update(User $user, Organisation $organisation): bool
    {
        return $user->can(Ability::UpdateOrganisation->value);
    }
}
