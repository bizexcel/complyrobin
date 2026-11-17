<?php

namespace App\Filament\App\Resources\Credentials\Pages;

use App\Filament\App\Resources\Credentials\AutomaticRotationPresenter;
use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Models\Credential;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCredential extends ViewRecord
{
    #[\Override]
    protected static string $resource = CredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (Credential $record): bool => $record->revoked_at === null),
            CredentialPresenter::recordRotationAction(),
            ...AutomaticRotationPresenter::actions(),
            CredentialPresenter::revokeAction(),
        ];
    }
}
