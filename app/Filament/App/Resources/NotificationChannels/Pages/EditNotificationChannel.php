<?php

namespace App\Filament\App\Resources\NotificationChannels\Pages;

use App\Core\Notifications\Actions\DeleteNotificationChannel;
use App\Core\Notifications\Actions\UpdateNotificationChannel;
use App\Core\Notifications\Models\NotificationChannel;
use App\Filament\App\Actor;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditNotificationChannel extends EditRecord
{
    #[\Override]
    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (NotificationChannel $record) => app(DeleteNotificationChannel::class)->handle(Actor::current(), $record)),
        ];
    }

    /**
     * Never send the saved webhook URL back to the browser.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['settings']);

        return [...$data, 'webhook_url' => null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof NotificationChannel, 404);

        return app(UpdateNotificationChannel::class)->handle(Actor::current(), $record, $data);
    }
}
