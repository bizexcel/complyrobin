<?php

namespace App\Filament\App\Resources\NotificationChannels\Schemas;

use App\Core\Notifications\Enums\ChannelType;
use App\Core\Notifications\Models\NotificationChannel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class NotificationChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(collect(ChannelType::cases())
                        ->filter(fn (ChannelType $type): bool => $type->isAvailable())
                        ->mapWithKeys(fn (ChannelType $type): array => [$type->value => $type->label()])->all())
                    ->required()
                    ->live()
                    ->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(100)->helperText('e.g. "#ops-alerts"'),
                // Write-only: the saved URL is never sent back to the browser.
                TextInput::make('webhook_url')
                    ->label('Webhook URL')
                    ->password()
                    ->maxLength(2048)
                    ->visible(fn (Get $get): bool => self::type($get)?->usesWebhook() ?? true)
                    ->required(fn (?NotificationChannel $record): bool => ! $record instanceof NotificationChannel)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (?NotificationChannel $record): string => $record instanceof NotificationChannel
                        ? 'Leave empty to keep the current URL. A new URL needs a new test message.'
                        : 'From a Slack incoming webhook, or a Teams workflow "When a Teams webhook request is received".'),
                TextInput::make('chat_id')
                    ->label('Telegram chat id')
                    ->visible(fn (Get $get): bool => self::type($get) === ChannelType::Telegram)
                    ->hiddenOn('edit')
                    ->required()
                    ->maxLength(21)
                    ->helperText('Add the ComplyRobin bot to your group, then enter the group\'s chat id, e.g. -1001234567890. We\'ll send a code to the chat to confirm it.'),
                Toggle::make('is_enabled')->label('Send reminders here')->default(true),
            ]);
    }

    private static function type(Get $get): ?ChannelType
    {
        $type = $get('type');

        return $type instanceof ChannelType ? $type : ChannelType::tryFrom((string) $type);
    }
}
