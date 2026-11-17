<?php

namespace App\Filament\Admin\Resources\Enquiries\Schemas;

use App\Core\Enquiries\Models\Enquiry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EnquiryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Enquiry')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('company')->placeholder('Not given'),
                        TextEntry::make('created_at')->label('Received')->dateTime('j M Y, H:i'),
                        TextEntry::make('message')->columnSpanFull()->prose(),
                    ]),
                Section::make('Handling')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('status')
                            ->state(fn (Enquiry $record): string => $record->handled_at === null ? 'Open' : 'Handled')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Open' ? 'warning' : 'success'),
                        TextEntry::make('handledBy.name')
                            ->label('Handled by')
                            ->placeholder('No one yet')
                            ->formatStateUsing(fn (string $state, Enquiry $record): string => $record->handled_at === null ? $state : "{$state}, {$record->handled_at->format('j M Y')}"),
                        TextEntry::make('note')->columnSpanFull()->placeholder('No note'),
                    ]),
            ]);
    }
}
