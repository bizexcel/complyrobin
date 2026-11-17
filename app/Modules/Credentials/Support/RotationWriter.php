<?php

namespace App\Modules\Credentials\Support;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use Carbon\CarbonInterface;

/**
 * Writes one rotation to the log, keeping when it had been due, and moves the credential's due date on. Call it inside
 * a transaction, with the credential locked; checking whether the rotation is allowed is the caller's job.
 */
final class RotationWriter
{
    public static function write(Credential $locked, ?User $rotatedBy, CarbonInterface $rotatedAt, ?CarbonInterface $expiresAt, ?string $notes): CredentialRotation
    {
        $rotation = CredentialRotation::query()->create([
            'credential_id' => $locked->getKey(),
            'rotated_by_id' => $rotatedBy?->getKey(),
            'rotated_at' => $rotatedAt,
            'due_at' => $locked->next_rotation_at,
            'notes' => $notes,
        ]);

        $locked->forceFill([
            'last_rotated_at' => $rotatedAt,
            'expires_at' => $expiresAt,
        ])->save();

        return $rotation;
    }
}
