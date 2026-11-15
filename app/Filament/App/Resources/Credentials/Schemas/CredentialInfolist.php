<?php

namespace App\Filament\App\Resources\Credentials\Schemas;

use App\Filament\App\Resources\Credentials\CredentialPresenter;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Enums\CredentialType;
use App\Modules\Credentials\Enums\Environment;
use App\Modules\Credentials\Models\Credential;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CredentialInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('reference'),
                TextEntry::make('status')
                    ->state(fn (Credential $record): CredentialStatus => CredentialPresenter::status($record))
                    ->formatStateUsing(fn (CredentialStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (CredentialStatus $state): string => CredentialPresenter::colour($state)),
                TextEntry::make('name'),
                TextEntry::make('application.name')->label('Application'),
                TextEntry::make('type')->formatStateUsing(fn (CredentialType $state): string => $state->label()),
                TextEntry::make('environment')->formatStateUsing(fn (Environment $state): string => $state->label()),
                TextEntry::make('owner.name')->label('Owner')->placeholder('No owner'),
                TextEntry::make('backupOwner.name')->label('Backup owner')->placeholder('None'),
                TextEntry::make('used_in')->label('Where it\'s used')->placeholder('—')->columnSpanFull(),
                TextEntry::make('hint')->placeholder('—'),
                TextEntry::make('rotation_days')->label('Rotate every')->suffix(' days')->placeholder('Only at expiry'),
                TextEntry::make('last_rotated_at')->label('Last rotated')->dateTime(timezone: CredentialPresenter::timezone())->placeholder('Never'),
                TextEntry::make('next_rotation_at')->label('Due')->date(timezone: CredentialPresenter::timezone()),
                TextEntry::make('expires_at')->label('Expires')->dateTime(timezone: CredentialPresenter::timezone())->placeholder('—'),
            ]);
    }
}
