<?php

namespace App\Filament\App\Resources\Credentials\Schemas;

use App\Modules\Credentials\Enums\CredentialType;
use App\Modules\Credentials\Enums\Environment;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CredentialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('application_id')
                    ->label('Application')
                    ->relationship('application', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        $application = $state === null ? null : Application::query()->with('vendor')->find($state);

                        if ($application?->vendor !== null && blank($get('rotation_days'))) {
                            $set('rotation_days', $application->vendor->rotation_days);
                        }
                    }),
                TextInput::make('name')->required()->maxLength(255)->helperText('What it is, e.g. "Production deploy token". Never the key itself.'),
                Select::make('type')
                    ->options(collect(CredentialType::cases())->mapWithKeys(fn (CredentialType $type): array => [$type->value => $type->label()])->all())
                    ->default(CredentialType::ApiToken->value)
                    ->required(),
                Select::make('environment')
                    ->options(collect(Environment::cases())->mapWithKeys(fn (Environment $environment): array => [$environment->value => $environment->label()])->all())
                    ->default(Environment::Production->value)
                    ->required(),
                Select::make('owner_id')->label('Owner')->relationship('owner', 'name')->searchable()->preload(),
                Select::make('backup_owner_id')->label('Backup owner')->relationship('backupOwner', 'name')->searchable()->preload(),
                Textarea::make('used_in')->label('Where it\'s used')->maxLength(2000)->helperText('e.g. "Laravel Cloud production: STRIPE_SECRET"')->columnSpanFull(),
                TextInput::make('hint')->maxLength(8)->helperText('Optional: the first or last few characters, to tell keys apart. Never the whole key.'),
                TextInput::make('rotation_days')->label('Rotate every (days)')->numeric()->minValue(1)->maxValue(1095),
                DateTimePicker::make('last_rotated_at')
                    ->label('Last rotated')
                    ->maxDate(now())
                    ->disabled(fn (?Credential $record): bool => $record?->rotations()->exists() ?? false)
                    ->helperText(fn (?Credential $record): ?string => $record?->rotations()->exists() ? 'Set by recorded rotations.' : null),
                DateTimePicker::make('expires_at')->label('Expires (if the vendor sets an expiry)'),
            ]);
    }
}
