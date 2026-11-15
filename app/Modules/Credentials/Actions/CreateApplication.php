<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\ApplicationRules;
use App\Modules\Credentials\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateApplication
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Application
    {
        Gate::forUser($actor)->authorize('create', Application::class);

        $validated = ApplicationRules::validate($data);

        return DB::transaction(fn (): Application => Application::query()->create($validated));
    }
}
