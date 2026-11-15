<?php

namespace App\Filament\App\Resources\Credentials;

use App\Filament\App\Resources\Credentials\Pages\CreateCredential;
use App\Filament\App\Resources\Credentials\Pages\EditCredential;
use App\Filament\App\Resources\Credentials\Pages\ListCredentials;
use App\Filament\App\Resources\Credentials\Pages\ViewCredential;
use App\Filament\App\Resources\Credentials\RelationManagers\HistoryRelationManager;
use App\Filament\App\Resources\Credentials\RelationManagers\RotationsRelationManager;
use App\Filament\App\Resources\Credentials\Schemas\CredentialForm;
use App\Filament\App\Resources\Credentials\Schemas\CredentialInfolist;
use App\Filament\App\Resources\Credentials\Tables\CredentialsTable;
use App\Modules\Credentials\Models\Credential;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CredentialResource extends Resource
{
    #[\Override]
    protected static ?string $model = Credential::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Credentials';

    #[\Override]
    protected static ?int $navigationSort = 1;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CredentialForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CredentialInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CredentialsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RotationsRelationManager::class,
            HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredentials::route('/'),
            'create' => CreateCredential::route('/create'),
            'view' => ViewCredential::route('/{record}'),
            'edit' => EditCredential::route('/{record}/edit'),
        ];
    }
}
