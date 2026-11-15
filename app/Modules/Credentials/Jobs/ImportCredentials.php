<?php

namespace App\Modules\Credentials\Jobs;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\CreateCredential;
use App\Modules\Credentials\Actions\StartCredentialImport;
use App\Modules\Credentials\Notifications\CredentialsImported;
use App\Modules\Credentials\Support\CredentialSpreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Imports an uploaded spreadsheet of credentials, row by row through CreateCredential as the member who uploaded it,
 * in the organisation it was uploaded in. A row that fails is skipped and reported; the rest are imported.
 */
class ImportCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $importedById, public readonly string $path) {}

    public function handle(Entitlements $entitlements, CreateCredential $createCredential): void
    {
        $disk = Storage::disk(StartCredentialImport::disk());

        try {
            $organisation = Organisation::query()->findOrFail(Tenancy::id());
            $actor = User::query()->find($this->importedById);

            // Downgraded, or the member has left, since uploading: import nothing.
            if (! $actor instanceof User || ! $actor->canAccessTenant($organisation) || ! $entitlements->allows(Feature::SpreadsheetImport)) {
                return;
            }

            $imported = 0;
            $failures = [];

            foreach (CredentialSpreadsheet::rows((string) $disk->get($this->path)) as $line => $row) {
                try {
                    $createCredential->handle($actor, CredentialSpreadsheet::toCredentialData($row));
                    $imported++;
                } catch (ValidationException $exception) {
                    $failures[$line] = (string) collect($exception->errors())->flatten()->first();
                } catch (AuthorizationException) {
                    $failures[$line] = 'You can no longer add credentials.';

                    break;
                }
            }

            $actor->notify(new CredentialsImported(
                $organisation->name,
                route('filament.app.resources.credentials.index', ['tenant' => $organisation->slug]),
                $imported,
                $failures,
            ));
        } finally {
            $disk->delete($this->path);
        }
    }
}
