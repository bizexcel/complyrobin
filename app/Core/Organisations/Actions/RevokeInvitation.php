<?php

namespace App\Core\Organisations\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Invitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class RevokeInvitation
{
    /**
     * Withdraw an open invitation: its link stops working and its seat is free again.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageMembers->value);

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has already been accepted.']);
        }

        $invitation->delete();
    }
}
