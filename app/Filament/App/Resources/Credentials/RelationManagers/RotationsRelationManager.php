<?php

namespace App\Filament\App\Resources\Credentials\RelationManagers;

use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Modules\Credentials\Models\CredentialRotation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The credential's rotation history. Read-only: rotations are recorded with the "Record rotation" action and never
 * changed afterwards.
 */
class RotationsRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'rotations';

    #[\Override]
    protected static ?string $title = 'Rotation history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rotated_at')->label('Rotated')->dateTime(timezone: CredentialPresenter::timezone())->sortable(),
                TextColumn::make('due_at')->label('Was due')->date(timezone: CredentialPresenter::timezone()),
                TextColumn::make('on_time')
                    ->label('')
                    ->state(fn (CredentialRotation $record): string => $record->isOnTime(CredentialPresenter::timezone()) ? 'On time' : 'Late')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'On time' ? 'success' : 'warning'),
                TextColumn::make('rotatedBy.name')->label('By'),
                TextColumn::make('notes')->limit(80)->wrap(),
            ])
            ->defaultSort('rotated_at', 'desc');
    }
}
