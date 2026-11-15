<?php

namespace App\Filament\App\Resources\Applications\Schemas;

use App\Modules\Credentials\Enums\Criticality;
use App\Modules\Credentials\Models\Vendor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('Vendor')
                    ->helperText('Pick from the catalogue, or leave empty for your own application.')
                    ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        $vendor = $state === null ? null : Vendor::query()->find($state);

                        if ($vendor === null) {
                            return;
                        }

                        if (blank($get('name'))) {
                            $set('name', $vendor->name);
                        }

                        if (blank($get('console_url'))) {
                            $set('console_url', $vendor->rotation_url ?? $vendor->website_url);
                        }
                    }),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('criticality')
                    ->options(collect(Criticality::cases())->mapWithKeys(fn (Criticality $criticality): array => [$criticality->value => $criticality->label()])->all())
                    ->default(Criticality::Medium->value)
                    ->required(),
                Select::make('owner_id')->label('Owner')->relationship('owner', 'name')->searchable()->preload(),
                Select::make('backup_owner_id')->label('Backup owner')->relationship('backupOwner', 'name')->searchable()->preload(),
                TextInput::make('console_url')->label('Console URL')->url()->maxLength(2048)->helperText('Where your team signs in to manage it (https).'),
                Textarea::make('notes')->maxLength(10000)->columnSpanFull(),
            ]);
    }
}
