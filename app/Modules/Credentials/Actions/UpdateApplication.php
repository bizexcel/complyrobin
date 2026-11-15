<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\ApplicationRules;
use App\Modules\Credentials\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateApplication
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Application $application, array $data): Application
    {
        Gate::forUser($actor)->authorize('update', $application);

        $validated = ApplicationRules::validate($data, $application);

        return DB::transaction(function () use ($application, $validated): Application {
            $application->update($validated);

            return $application;
        });
    }
}
