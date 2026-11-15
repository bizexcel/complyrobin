<?php

namespace App\Filament\App\Resources\Applications\Tables;

use App\Core\Identity\Models\User;
use App\Modules\Credentials\Actions\DeleteApplication;
use App\Modules\Credentials\Enums\Criticality;
use App\Modules\Credentials\Models\Application;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('vendor.name')->label('Vendor'),
                TextColumn::make('criticality')
                    ->badge()
                    ->formatStateUsing(fn (Criticality $state): string => $state->label())
                    ->color(fn (Criticality $state): string => match ($state) {
                        Criticality::Critical => 'danger',
                        Criticality::High => 'warning',
                        Criticality::Medium => 'info',
                        Criticality::Low => 'gray',
                    }),
                TextColumn::make('owner.name')->label('Owner'),
                TextColumn::make('backupOwner.name')->label('Backup owner'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(function (Application $record): void {
                    $user = Filament::auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(DeleteApplication::class)->handle($user, $record);
                }),
            ]);
    }
}
