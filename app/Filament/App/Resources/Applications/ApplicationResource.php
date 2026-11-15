<?php

namespace App\Filament\App\Resources\Applications;

use App\Filament\App\Resources\Applications\Pages\CreateApplication;
use App\Filament\App\Resources\Applications\Pages\EditApplication;
use App\Filament\App\Resources\Applications\Pages\ListApplications;
use App\Filament\App\Resources\Applications\Schemas\ApplicationForm;
use App\Filament\App\Resources\Applications\Tables\ApplicationsTable;
use App\Modules\Credentials\Models\Application;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ApplicationResource extends Resource
{
    #[\Override]
    protected static ?string $model = Application::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Credentials';

    #[\Override]
    protected static ?int $navigationSort = 2;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'create' => CreateApplication::route('/create'),
            'edit' => EditApplication::route('/{record}/edit'),
        ];
    }
}
