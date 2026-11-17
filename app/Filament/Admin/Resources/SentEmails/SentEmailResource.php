<?php

namespace App\Filament\Admin\Resources\SentEmails;

use App\Core\Notifications\Models\SentEmail;
use App\Filament\Admin\Resources\SentEmails\Pages\ListSentEmails;
use App\Filament\Admin\Resources\SentEmails\Tables\SentEmailsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every email the app has sent: who to, the subject and which email it was. Read-only, and never the body.
 */
class SentEmailResource extends Resource
{
    #[\Override]
    protected static ?string $model = SentEmail::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    #[\Override]
    protected static ?string $navigationLabel = 'Email log';

    #[\Override]
    protected static ?string $modelLabel = 'sent email';

    #[\Override]
    protected static ?int $navigationSort = 25;

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

    public static function table(Table $table): Table
    {
        return SentEmailsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSentEmails::route('/'),
        ];
    }
}
