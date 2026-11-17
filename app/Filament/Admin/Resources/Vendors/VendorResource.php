<?php

namespace App\Filament\Admin\Resources\Vendors;

use App\Filament\Admin\Actor;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Filament\Admin\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Admin\Resources\Vendors\Tables\VendorsTable;
use App\Modules\Credentials\Models\Vendor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The vendor catalogue every organisation picks from. Staff see it; Admins add and edit vendors.
 */
class VendorResource extends Resource
{
    #[\Override]
    protected static ?string $model = Vendor::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    #[\Override]
    protected static ?string $navigationLabel = 'Vendor catalogue';

    #[\Override]
    protected static ?int $navigationSort = 10;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return Actor::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return Actor::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
        ];
    }
}
