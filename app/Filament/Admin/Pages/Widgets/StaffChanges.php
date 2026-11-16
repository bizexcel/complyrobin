<?php

namespace App\Filament\Admin\Pages\Widgets;

use App\Core\Identity\Models\StaffRoleChange;
use App\Filament\Admin\Actor;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Every change to a staff role, newest first, on the Staff page: whom, from what to what, by whom and when.
 */
class StaffChanges extends TableWidget
{
    #[\Override]
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Actor::isAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Staff changes')
            ->query(StaffRoleChange::query()->with(['user', 'changedBy']))
            ->defaultSort(fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading('No changes yet')
            ->columns([
                TextColumn::make('user.name')->label('Who')->state(fn (StaffRoleChange $record): string => $record->user->name ?? 'Deleted account'),
                TextColumn::make('from_role')->label('From')->state(fn (StaffRoleChange $record): string => $record->from_role?->label() ?? 'Not staff'),
                TextColumn::make('to_role')->label('To')->state(fn (StaffRoleChange $record): string => $record->to_role?->label() ?? 'Not staff'),
                TextColumn::make('changedBy.name')->label('Changed by')->state(fn (StaffRoleChange $record): string => $record->changedBy->name ?? 'Server console'),
                TextColumn::make('created_at')->label('When')->dateTime(),
            ]);
    }
}
