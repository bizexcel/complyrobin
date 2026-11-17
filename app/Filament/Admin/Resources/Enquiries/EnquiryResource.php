<?php

namespace App\Filament\Admin\Resources\Enquiries;

use App\Core\Enquiries\Models\Enquiry;
use App\Filament\Admin\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Admin\Resources\Enquiries\Pages\ViewEnquiry;
use App\Filament\Admin\Resources\Enquiries\Schemas\EnquiryInfolist;
use App\Filament\Admin\Resources\Enquiries\Tables\EnquiriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Messages from the www contact form. Staff read them; Admins mark them handled or reopen them.
 */
class EnquiryResource extends Resource
{
    #[\Override]
    protected static ?string $model = Enquiry::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    #[\Override]
    protected static ?int $navigationSort = 20;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Enquiry::query()->whereNull('handled_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Not handled yet';
    }

    public static function infolist(Schema $schema): Schema
    {
        return EnquiryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EnquiriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
            'view' => ViewEnquiry::route('/{record}'),
        ];
    }
}
