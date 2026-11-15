<?php

namespace App\Filament\App\Resources\Credentials\Pages;

use App\Filament\App\Actor;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Actions\UpdateCredential;
use App\Modules\Credentials\Models\Credential;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCredential extends EditRecord
{
    #[\Override]
    protected static string $resource = CredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Credential, 404);

        return app(UpdateCredential::class)->handle(Actor::current(), $record, $data);
    }
}
