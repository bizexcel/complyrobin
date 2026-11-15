<?php

namespace App\Filament\App\Resources\NotificationChannels\Tables;

use App\Core\Notifications\Actions\DeleteNotificationChannel;
use App\Core\Notifications\Actions\SendTestMessage;
use App\Core\Notifications\Actions\VerifyNotificationChannel;
use App\Core\Notifications\Enums\ChannelType;
use App\Core\Notifications\Models\NotificationChannel;
use App\Filament\App\Actor;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->formatStateUsing(fn (ChannelType $state): string => $state->label()),
                IconColumn::make('verified_at')->label('Confirmed')->boolean(),
                IconColumn::make('is_enabled')->label('Enabled')->boolean(),
            ])
            ->recordActions([
                Action::make('sendTest')
                    ->label(fn (NotificationChannel $record): string => $record->type->needsVerificationCode() ? 'Send code' : 'Send test message')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (NotificationChannel $record): void {
                        try {
                            app(SendTestMessage::class)->handle(Actor::current(), $record);
                            Notification::make()->title($record->type->needsVerificationCode()
                                ? 'Code sent to the chat. Enter it to finish.'
                                : 'Test message sent. This channel will now get reminders.')->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Action::make('verify')
                    ->label('Enter code')
                    ->icon('heroicon-o-key')
                    ->visible(fn (NotificationChannel $record): bool => $record->type->needsVerificationCode() && $record->verified_at === null)
                    ->schema([TextInput::make('code')->label('Code from the chat')->required()->maxLength(6)])
                    ->action(function (NotificationChannel $record, array $data): void {
                        app(VerifyNotificationChannel::class)->handle(Actor::current(), $record, (string) $data['code']);
                        Notification::make()->title('Confirmed. This chat will now get reminders.')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make()->using(fn (NotificationChannel $record) => app(DeleteNotificationChannel::class)->handle(Actor::current(), $record)),
            ]);
    }
}
