<?php

namespace App\Filament\App\Resources\Credentials\RelationManagers;

use App\Core\Audit\AuditTrail;
use App\Core\Audit\Models\Activity;
use App\Filament\App\Resources\Credentials\CredentialPresenter;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who changed the credential, and what changed, from the audit trail. Read-only.
 */
class HistoryRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'activitiesAsSubject';

    #[\Override]
    protected static ?string $title = 'History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(timezone: CredentialPresenter::timezone())->sortable(),
                TextColumn::make('who')->state(fn (Activity $record): string => AuditTrail::who($record)),
                TextColumn::make('event')->badge(),
                TextColumn::make('changes')->state(fn (Activity $record): string => AuditTrail::changes($record))->wrap(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
