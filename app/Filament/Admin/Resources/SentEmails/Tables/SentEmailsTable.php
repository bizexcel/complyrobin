<?php

namespace App\Filament\Admin\Resources\SentEmails\Tables;

use App\Core\Notifications\Models\SentEmail;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SentEmailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Sent')->dateTime('j M Y, H:i:s')->sortable(),
                TextColumn::make('to')->searchable()->wrap(),
                TextColumn::make('subject')->searchable()->wrap(),
                TextColumn::make('kind')
                    ->label('Email')
                    ->formatStateUsing(fn (string $state): string => self::kindLabel($state))
                    ->placeholder('Other'),
                TextColumn::make('message_id')->label('Provider id')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label('Email')
                    ->options(fn (): array => SentEmail::query()->whereNotNull('kind')->distinct()->orderBy('kind')->pluck('kind')
                        ->mapWithKeys(fn (string $kind): array => [$kind => self::kindLabel($kind)])->all()),
            ]);
    }

    /**
     * "App\Modules\Credentials\Notifications\CredentialsDue" reads as "Credentials due".
     */
    public static function kindLabel(string $kind): string
    {
        return Str::ucfirst(Str::lower(Str::headline(class_basename($kind))));
    }
}
