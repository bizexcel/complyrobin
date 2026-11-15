<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Files\PrivateDisk;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Jobs\ImportCredentials;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialSpreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartCredentialImport
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Check a spreadsheet of credentials and import it in the queue, each row through CreateCredential as the member
     * who uploaded it. A Team feature. The file is kept under the organisation's folder until the import has run.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $csv): int
    {
        Gate::forUser($actor)->authorize('create', Credential::class);

        if (! $this->entitlements->allows(Feature::SpreadsheetImport)) {
            throw ValidationException::withMessages(['file' => 'Importing from a spreadsheet is part of Team. Upgrade to use it.']);
        }

        $rows = count(CredentialSpreadsheet::rows($csv));
        $path = 'org/'.Tenancy::id().'/imports/'.Str::uuid7().'.csv';

        Storage::disk(PrivateDisk::name())->put($path, $csv);
        ImportCredentials::dispatch($actor->getKey(), $path);

        return $rows;
    }
}
