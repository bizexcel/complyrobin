<?php

namespace App\Filament\App\Resources\Credentials\Pages;

use App\Filament\App\Actor;
use App\Filament\App\Resources\Credentials\CredentialResource;
use App\Modules\Credentials\Actions\CreateCredential as CreateCredentialAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCredential extends CreateRecord
{
    #[\Override]
    protected static string $resource = CredentialResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCredentialAction::class)->handle(Actor::current(), $data);
    }
}
