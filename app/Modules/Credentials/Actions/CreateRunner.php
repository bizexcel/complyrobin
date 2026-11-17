<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Runner;
use App\Modules\Credentials\Support\RunnerTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CreateRunner
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Add a rotation runner. Returns its token too: only a hash of it is stored, so it can be shown just this once.
     *
     * @param  array{name?: mixed}  $data
     * @return array{runner: Runner, token: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize(Ability::ManageCredentials->value);

        if (! $this->entitlements->allows(Feature::AutomaticRotation)) {
            throw ValidationException::withMessages(['name' => 'Automatic rotation is part of the Team plan.']);
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:80', Rule::unique('runners')->where('organisation_id', Tenancy::id())],
        ], [
            'name.unique' => 'You already have a runner with this name.',
        ])->validate();

        $token = RunnerTokens::generate();

        $runner = DB::transaction(fn (): Runner => Runner::query()->create([
            'name' => $validated['name'],
            'token' => RunnerTokens::hash($token),
        ]));

        return ['runner' => $runner, 'token' => $token];
    }
}
