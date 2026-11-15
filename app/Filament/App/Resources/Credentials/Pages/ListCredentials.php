<?php

namespace App\Filament\App\Resources\Credentials\Pages;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Entitlements\Enums\Limit;
use App\Filament\App\Actor;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Actions\StartCredentialImport;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Support\CredentialSpreadsheet;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCredentials extends ListRecords
{
    #[\Override]
    protected static string $resource = CredentialResource::class;

    public function getSubheading(): string
    {
        $entitlements = app(Entitlements::class);
        $used = Credential::query()->active()->count();
        $limit = $entitlements->limit(Limit::Credentials);

        return $limit === null
            ? "{$used} credentials · unlimited on {$entitlements->plan()->name}"
            : "{$used} of {$limit} credentials on {$entitlements->plan()->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => Actor::current()->can('create', Credential::class) && app(Entitlements::class)->allows(Feature::SpreadsheetImport))
                ->modalDescription('One credential per row, up to '.CredentialSpreadsheet::MAX_ROWS.' rows. Applications are matched by name and owners by their email in People. Never put the secret itself in the file.')
                ->schema([
                    FileUpload::make('file')
                        ->label('Spreadsheet (CSV)')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(CredentialSpreadsheet::MAX_BYTES / 1024)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->extraModalFooterActions([
                    Action::make('template')
                        ->label('Download the template')
                        ->link()
                        ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                            echo CredentialSpreadsheet::template();
                        }, 'complyrobin-credentials-template.csv', ['Content-Type' => 'text/csv; charset=utf-8'])),
                ])
                ->action(function (array $data): void {
                    /** @var TemporaryUploadedFile $file */
                    $file = $data['file'];

                    try {
                        $rows = app(StartCredentialImport::class)->handle(Actor::current(), (string) $file->get());
                        Notification::make()->title("Importing {$rows} ".($rows === 1 ? 'row' : 'rows').'. We\'ll let you know when it\'s done.')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
