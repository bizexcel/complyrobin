<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class DeleteCredential
{
    /**
     * Soft-delete a credential; its history stays.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Credential $credential): void
    {
        Gate::forUser($actor)->authorize('delete', $credential);

        $credential->delete();
    }
}
