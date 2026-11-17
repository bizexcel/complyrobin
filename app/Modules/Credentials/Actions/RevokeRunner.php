<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Runner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final readonly class RevokeRunner
{
    /**
     * Stop a runner working straight away. Final; revoking it again changes nothing.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, Runner $runner): Runner
    {
        Gate::forUser($actor)->authorize(Ability::ManageCredentials->value);

        if ($runner->revoked_at === null) {
            $runner->forceFill(['revoked_at' => now()])->save();
        }

        return $runner;
    }
}
