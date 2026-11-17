<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class RequestRotation
{
    /**
     * Ask the credential's runner to rotate it the next time it checks in, whether or not it is due.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential): AutomaticRotation
    {
        Gate::forUser($actor)->authorize('rotate', $credential);

        $automaticRotation = $credential->automaticRotation()->first();

        if (! $automaticRotation instanceof AutomaticRotation) {
            throw ValidationException::withMessages(['credential' => 'Set up automatic rotation for this credential first.']);
        }

        if ($credential->revoked_at !== null) {
            throw ValidationException::withMessages(['credential' => 'A revoked credential can\'t be rotated.']);
        }

        $automaticRotation->forceFill(['requested_at' => now()])->save();

        return $automaticRotation;
    }
}
