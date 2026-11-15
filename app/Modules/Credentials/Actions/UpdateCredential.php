<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\CredentialRules;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateCredential
{
    /**
     * Change a credential's details. A revoked credential can't be changed (only deleted).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential, array $data): Credential
    {
        Gate::forUser($actor)->authorize('update', $credential);

        if ($credential->revoked_at !== null) {
            throw ValidationException::withMessages(['name' => 'A revoked credential can\'t be changed.']);
        }

        $validated = CredentialRules::validate($data);

        return DB::transaction(function () use ($credential, $validated): Credential {
            $credential->update($validated);

            return $credential->refresh();
        });
    }
}
