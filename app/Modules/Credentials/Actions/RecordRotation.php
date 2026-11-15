<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Rules\NotASecret;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordRotation
{
    /**
     * Record that the credential was rotated, keeping when it had been due, and move its due date on.
     *
     * Allowed for anyone who rotates credentials, and for the credential's own owner and backup owner.
     *
     * @param  array{rotated_at?: string|null, expires_at?: string|null, notes?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential, array $data = []): CredentialRotation
    {
        Gate::forUser($actor)->authorize('rotate', $credential);

        return DB::transaction(function () use ($actor, $credential, $data): CredentialRotation {
            $locked = Credential::query()->lockForUpdate()->findOrFail($credential->getKey());

            $validated = Validator::make(['rotated_at' => $data['rotated_at'] ?? now()->toIso8601String(), ...$data], [
                'rotated_at' => ['required', 'date', 'before_or_equal:now', ...($locked->last_rotated_at === null ? [] : ['after:'.$locked->last_rotated_at->toIso8601String()])],
                'expires_at' => [$locked->expires_at === null ? 'nullable' : 'required', 'date', 'after:rotated_at'],
                'notes' => ['nullable', 'string', 'max:5000', new NotASecret],
            ], [
                'rotated_at.after' => 'This credential was last rotated later than that.',
                'expires_at.required' => 'This credential expires: enter the new key\'s expiry date.',
            ])->after(function ($validator) use ($locked): void {
                if ($locked->revoked_at !== null) {
                    $validator->errors()->add('rotated_at', 'A revoked credential can\'t be rotated.');
                }
            })->validate();

            $rotation = CredentialRotation::query()->create([
                'credential_id' => $locked->getKey(),
                'rotated_by_id' => $actor->getKey(),
                'rotated_at' => $validated['rotated_at'],
                'due_at' => $locked->next_rotation_at,
                'notes' => $validated['notes'] ?? null,
            ]);

            $locked->forceFill([
                'last_rotated_at' => Carbon::parse($validated['rotated_at']),
                'expires_at' => $validated['expires_at'] ?? null,
            ])->save();

            $credential->refresh();

            return $rotation;
        });
    }
}
