<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class RemoveAutomaticRotation
{
    /**
     * Switch off automatic rotation. The credential stays, and is rotated by hand again. Not while a runner is in the
     * middle of rotating it.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential): void
    {
        Gate::forUser($actor)->authorize('update', $credential);

        if (RotationRun::query()->where('credential_id', $credential->getKey())->where('status', RotationRunStatus::Claimed)->exists()) {
            throw ValidationException::withMessages(['credential' => 'This credential is being rotated right now. Try again in an hour.']);
        }

        $credential->automaticRotation()->first()?->delete();
    }
}
