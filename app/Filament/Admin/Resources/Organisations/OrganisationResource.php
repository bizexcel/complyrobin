<?php

namespace App\Filament\Admin\Resources\Organisations;

use App\Core\Organisations\Models\Organisation;
use App\Filament\Admin\Resources\Organisations\Pages\ListOrganisations;
use App\Filament\Admin\Resources\Organisations\Tables\OrganisationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every organisation on the platform, for staff.
 */
class OrganisationResource extends Resource
{
    #[\Override]
    protected static ?string $model = Organisation::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return OrganisationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganisations::route('/'),
        ];
    }
}
