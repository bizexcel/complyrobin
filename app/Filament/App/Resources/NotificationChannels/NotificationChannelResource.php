<?php

namespace App\Filament\App\Resources\NotificationChannels;

use App\Core\Notifications\Models\NotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\CreateNotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\EditNotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Filament\App\Resources\NotificationChannels\Schemas\NotificationChannelForm;
use App\Filament\App\Resources\NotificationChannels\Tables\NotificationChannelsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NotificationChannelResource extends Resource
{
    #[\Override]
    protected static ?string $model = NotificationChannel::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    #[\Override]
    protected static ?string $navigationLabel = 'Chat channels';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return NotificationChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationChannelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationChannels::route('/'),
            'create' => CreateNotificationChannel::route('/create'),
            'edit' => EditNotificationChannel::route('/{record}/edit'),
        ];
    }
}
