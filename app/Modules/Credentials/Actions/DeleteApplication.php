<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class DeleteApplication
{
    /**
     * Soft-delete the application.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Application $application): void
    {
        Gate::forUser($actor)->authorize('delete', $application);

        $application->delete();
    }
}
