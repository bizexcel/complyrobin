<?php

namespace App\Filament\App\Resources\Credentials;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\HtmlString;

/**
 * Display helpers shared by the credential table, view page and revoke action.
 */
final class CredentialPresenter
{
    public static function timezone(): string
    {
        $organisation = Filament::getTenant();

        return $organisation instanceof Organisation ? $organisation->timezone : (string) config('app.timezone');
    }

    public static function status(Credential $credential): CredentialStatus
    {
        return $credential->status(timezone: self::timezone());
    }

    public static function colour(CredentialStatus $status): string
    {
        return match ($status) {
            CredentialStatus::Overdue, CredentialStatus::Expired => 'danger',
            CredentialStatus::DueSoon => 'warning',
            CredentialStatus::Active => 'success',
            CredentialStatus::Revoked => 'gray',
        };
    }

    public static function recordRotationAction(): Action
    {
        return Action::make('recordRotation')
            ->label('Record rotation')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->visible(fn (Credential $record): bool => $record->revoked_at === null)
            ->authorize(fn (Credential $record): bool => Actor::current()->can('rotate', $record))
            ->modalDescription(fn (Credential $record): HtmlString|string => self::whereToRotate($record))
            ->schema(fn (Credential $record): array => [
                DateTimePicker::make('rotated_at')->label('Rotated at')->default(now())->maxDate(now())->required(),
                DateTimePicker::make('expires_at')->label('New key expires')->visible($record->expires_at !== null)->required($record->expires_at !== null),
                Textarea::make('notes')->maxLength(5000)->helperText('What was done, e.g. "New token set in Laravel Cloud prod". Never the key.'),
                FileUpload::make('evidence')
                    ->label('Evidence (Team)')
                    ->helperText('Screenshots or exports showing the new key is in place. PDF, PNG, JPG, WebP or text, up to 10 MB each.')
                    ->multiple()
                    ->maxFiles((int) config('credentials.evidence.max_files'))
                    ->maxSize((int) config('credentials.evidence.max_kilobytes'))
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/plain'])
                    ->storeFiles(false)
                    ->visible(fn (): bool => app(Entitlements::class)->allows(Feature::EvidenceReport)),
            ])
            ->action(fn (Credential $record, array $data) => app(RecordRotation::class)->handle(Actor::current(), $record, $data));
    }

    /**
     * The vendor's "where to rotate" page, or the application's console, if known.
     */
    private static function whereToRotate(Credential $credential): HtmlString|string
    {
        $application = $credential->application;
        $url = $application instanceof Application ? ($application->vendor->rotation_url ?? $application->console_url) : null;

        return $url === null
            ? 'Rotate the key with the vendor, update where it is used, then record it here.'
            : new HtmlString('Rotate it at <a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="underline">'.e($url).'</a>, update where it is used, then record it here.');
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Revoked credentials are no longer reminded about and stop counting towards your plan. This can\'t be undone.')
            ->visible(fn (Credential $record): bool => $record->revoked_at === null)
            ->authorize(fn (Credential $record): bool => Actor::current()->can('revoke', $record))
            ->action(fn (Credential $record) => app(RevokeCredential::class)->handle(Actor::current(), $record));
    }
}
