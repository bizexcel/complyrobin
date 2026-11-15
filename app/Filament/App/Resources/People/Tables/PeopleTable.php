<?php

namespace App\Filament\App\Resources\People\Tables;

use App\Core\Identity\Models\User;
use App\Core\People\Actions\DeletePerson;
use App\Core\People\Models\Person;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PeopleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('job_title')->searchable(),
                TextColumn::make('department.name')->label('Department')->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(function (Person $record): void {
                    $user = Filament::auth()->user();
                    abort_unless($user instanceof User, 403);
                    app(DeletePerson::class)->handle($user, $record);
                }),
            ]);
    }
}
