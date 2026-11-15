<?php

namespace App\Filament\App\Resources\Applications\Pages;

use App\Core\Identity\Models\User;
use App\Filament\App\Resources\Applications\ApplicationResource;
use App\Modules\Credentials\Actions\DeleteApplication;
use App\Modules\Credentials\Actions\UpdateApplication;
use App\Modules\Credentials\Models\Application;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditApplication extends EditRecord
{
    #[\Override]
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Application $record) => app(DeleteApplication::class)->handle($this->actor(), $record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Application, 404);

        return app(UpdateApplication::class)->handle($this->actor(), $record, $data);
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
