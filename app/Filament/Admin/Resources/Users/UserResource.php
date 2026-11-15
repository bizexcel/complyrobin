<?php

namespace App\Filament\Admin\Resources\Users;

use App\Core\Identity\Models\User;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every user on the platform, for staff. Read-only.
 */
class UserResource extends Resource
{
    #[\Override]
    protected static ?string $model = User::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }
}
