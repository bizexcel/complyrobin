<?php

namespace App\Filament\App\Resources\People;

use App\Core\People\Models\Person;
use App\Filament\App\Resources\People\Pages\CreatePerson;
use App\Filament\App\Resources\People\Pages\EditPerson;
use App\Filament\App\Resources\People\Pages\ListPeople;
use App\Filament\App\Resources\People\Schemas\PersonForm;
use App\Filament\App\Resources\People\Tables\PeopleTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PersonResource extends Resource
{
    #[\Override]
    protected static ?string $model = Person::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PersonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PeopleTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }
}
