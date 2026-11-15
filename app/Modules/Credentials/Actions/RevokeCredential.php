<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RevokeCredential
{
    /**
     * Mark a credential as revoked: it stops being reminded about and stops counting towards the plan's limit. Final.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential): Credential
    {
        Gate::forUser($actor)->authorize('revoke', $credential);

        if ($credential->revoked_at !== null) {
            throw ValidationException::withMessages(['credential' => 'This credential is already revoked.']);
        }

        $credential->forceFill(['revoked_at' => now()])->save();

        return $credential;
    }
}
