<?php

namespace App\Filament\Admin\Resources\Enquiries\Tables;

use App\Core\Enquiries\Models\Enquiry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime('j M Y, H:i')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('company')->searchable()->placeholder('Not given'),
                TextColumn::make('message')->limit(60)->wrap(),
                TextColumn::make('status')
                    ->state(fn (Enquiry $record): string => $record->handled_at === null ? 'Open' : 'Handled')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Open' ? 'warning' : 'success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('handled')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Handled')
                    ->falseLabel('Open')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('handled_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('handled_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
