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

final class UpdatePerson
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Person $person, array $data): Person
    {
        Gate::forUser($actor)->authorize('update', $person);

        $validated = Validator::make($data, PersonRules::rules())->validate();

        return DB::transaction(function () use ($person, $validated): Person {
            $person->update($validated);

            return $person;
        });
    }
}
