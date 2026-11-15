<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteApplication
{
    /**
     * Soft-delete the application. Refused while it still has active credentials: revoke or move them first.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Application $application): void
    {
        Gate::forUser($actor)->authorize('delete', $application);

        if (Credential::query()->active()->where('application_id', $application->getKey())->exists()) {
            throw ValidationException::withMessages(['application' => 'This application still has active credentials. Revoke or delete them first.']);
        }

        $application->delete();
    }
}
