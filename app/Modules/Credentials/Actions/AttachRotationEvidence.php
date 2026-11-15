<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Modules\Credentials\Models\CredentialRotation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AttachRotationEvidence
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Attach evidence files (a screenshot, an export) to a recorded rotation. A Team feature. Files are stored under the
     * organisation's own folder and only ever served through short-lived signed links.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, CredentialRotation $rotation, array $files): void
    {
        $credential = $rotation->credential;
        abort_if($credential === null, 404);
        Gate::forUser($actor)->authorize('rotate', $credential);

        self::validate($files, $rotation->getMedia('evidence')->count(), $this->entitlements);

        foreach ($files as $file) {
            $rotation->addMedia($file)->toMediaCollection('evidence');
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public static function validate(array $files, int $alreadyAttached, Entitlements $entitlements): void
    {
        if ($files !== [] && ! $entitlements->allows(Feature::EvidenceReport)) {
            throw ValidationException::withMessages(['evidence' => 'Evidence files are part of Team. Upgrade to attach them.']);
        }

        $evidence = config('credentials.evidence');

        Validator::make(['evidence' => $files], [
            'evidence' => ['array', 'max:'.($evidence['max_files'] - $alreadyAttached)],
            'evidence.*' => ['file', 'max:'.$evidence['max_kilobytes'], 'mimes:'.implode(',', $evidence['mimes'])],
        ], [
            'evidence.max' => "A rotation can have at most {$evidence['max_files']} evidence files.",
        ])->validate();
    }
}
