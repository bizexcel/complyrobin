<?php

namespace App\Filament\App\Resources\NotificationChannels\Pages;

use App\Core\Notifications\Actions\CreateNotificationChannel as CreateNotificationChannelAction;
use App\Filament\App\Actor;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateNotificationChannel extends CreateRecord
{
    #[\Override]
    protected static string $resource = NotificationChannelResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateNotificationChannelAction::class)->handle(Actor::current(), $data);
    }
}
