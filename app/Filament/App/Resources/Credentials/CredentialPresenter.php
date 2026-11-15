<?php

namespace App\Filament\App\Resources\Credentials;

use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Actor;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use Filament\Actions\Action;
use Filament\Facades\Filament;

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
