<?php

namespace App\Filament\App\Resources\Applications\Pages;

use App\Core\Identity\Models\User;
use App\Filament\App\Resources\Applications\ApplicationResource;
use App\Modules\Credentials\Actions\CreateApplication as CreateApplicationAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApplication extends CreateRecord
{
    #[\Override]
    protected static string $resource = ApplicationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return app(CreateApplicationAction::class)->handle($user, $data);
    }
}
