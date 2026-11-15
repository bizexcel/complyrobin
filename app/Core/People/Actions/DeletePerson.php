<?php

namespace App\Core\People\Actions;

use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class DeletePerson
{
    /**
     * Soft-delete the person: references to them elsewhere stay readable.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Person $person): void
    {
        Gate::forUser($actor)->authorize('delete', $person);

        $person->delete();
    }
}
