<?php

namespace App\Filament\App\Resources\People\Pages;

use App\Core\Identity\Models\User;
use App\Core\People\Actions\CreatePerson as CreatePersonAction;
use App\Filament\App\Resources\People\PersonResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePerson extends CreateRecord
{
    #[\Override]
    protected static string $resource = PersonResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return app(CreatePersonAction::class)->handle($user, $data);
    }
}
