<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\CredentialRules;
use App\Modules\Credentials\Models\Credential;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
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

        // Once rotations are recorded, the last rotation comes from them: record a rotation instead.
        if ($credential->rotations()->exists() && array_key_exists('last_rotated_at', $data)
            && ! $this->sameMoment($data['last_rotated_at'], $credential->last_rotated_at)) {
            throw ValidationException::withMessages(['last_rotated_at' => 'Record a rotation to change when it was last rotated.']);
        }

        $validated = CredentialRules::validate($data);

        return DB::transaction(function () use ($credential, $validated): Credential {
            $credential->update($validated);

            return $credential->refresh();
        });
    }

    private function sameMoment(mixed $given, ?CarbonInterface $current): bool
    {
        if (blank($given) || ! $current instanceof CarbonInterface) {
            return blank($given) && ! $current instanceof CarbonInterface;
        }

        return Carbon::parse((string) $given)->startOfSecond()->equalTo($current->copy()->startOfSecond());
    }
}
