<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Rules\NotASecret;
use App\Modules\Credentials\Support\RotationWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class RecordRotation
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Record that the credential was rotated, keeping when it had been due, and move its due date on.
     *
     * Allowed for anyone who rotates credentials, and for the credential's own owner and backup owner. Evidence files
     * (Team) are checked before anything is written.
     *
     * @param  array{rotated_at?: string|null, expires_at?: string|null, notes?: string|null, evidence?: array<mixed>|null}  $data  evidence: uploaded files; anything else is ignored
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Credential $credential, array $data = []): CredentialRotation
    {
        Gate::forUser($actor)->authorize('rotate', $credential);

        $evidence = array_values(array_filter($data['evidence'] ?? [], fn (mixed $file): bool => $file instanceof UploadedFile));
        unset($data['evidence']);
        AttachRotationEvidence::validate($evidence, 0, $this->entitlements);

        return DB::transaction(function () use ($actor, $credential, $data, $evidence): CredentialRotation {
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

            $rotation = RotationWriter::write(
                $locked,
                $actor,
                Carbon::parse($validated['rotated_at']),
                isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
                $validated['notes'] ?? null,
            );

            foreach ($evidence as $file) {
                $rotation->addMedia($file)->toMediaCollection('evidence');
            }

            $credential->refresh();

            return $rotation;
        });
    }
}
