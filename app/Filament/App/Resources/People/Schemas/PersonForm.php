<?php

namespace App\Filament\App\Resources\People\Schemas;

use App\Core\Organisations\Models\Organisation;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PersonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(20)->helperText('International format, e.g. +60 12 345 6789'),
                TextInput::make('job_title')->maxLength(255),
                Select::make('department_id')->label('Department')->relationship('department', 'name')->searchable()->preload(),
                Select::make('user_id')
                    ->label('Member account')
                    ->helperText('Link a member so reminders can reach them in ComplyRobin.')
                    ->options(fn (): array => self::members())
                    ->searchable(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function members(): array
    {
        $organisation = Filament::getTenant();

        return $organisation instanceof Organisation
            ? $organisation->users()->orderBy('name')->pluck('name', 'users.id')->all()
            : [];
    }
}
