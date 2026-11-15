<?php

namespace App\Core\People\Actions;

use App\Core\Identity\Models\User;
use App\Core\People\Models\Person;
use App\Core\People\PersonRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreatePerson
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): Person
    {
        Gate::forUser($actor)->authorize('create', Person::class);

        $validated = Validator::make($data, PersonRules::rules())->validate();

        return DB::transaction(fn (): Person => Person::query()->create($validated));
    }
}
