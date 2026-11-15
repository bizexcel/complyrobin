<?php

namespace App\Filament\App\Resources\People\Pages;

use App\Core\Identity\Models\User;
use App\Core\People\Actions\DeletePerson;
use App\Core\People\Actions\UpdatePerson;
use App\Core\People\Models\Person;
use App\Filament\App\Resources\People\PersonResource;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPerson extends EditRecord
{
    #[\Override]
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Person $record) => app(DeletePerson::class)->handle($this->actor(), $record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Person, 404);

        return app(UpdatePerson::class)->handle($this->actor(), $record, $data);
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
