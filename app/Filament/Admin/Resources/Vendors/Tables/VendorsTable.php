<?php

namespace App\Filament\Admin\Resources\Vendors\Tables;

use App\Filament\Admin\Actor;
use App\Modules\Credentials\Actions\UpdateVendor;
use App\Modules\Credentials\Enums\VendorCategory;
use App\Modules\Credentials\Models\Vendor;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->color('gray'),
                TextColumn::make('category')->formatStateUsing(fn (VendorCategory $state): string => $state->label())->sortable(),
                TextColumn::make('rotation_days')->label('Suggested rotation')->suffix(' days')->sortable(),
                TextColumn::make('rotation_url')->label('Where to rotate')->limit(40)->placeholder('Website only')->url(fn (Vendor $record): ?string => $record->rotation_url, shouldOpenInNewTab: true),
                TextColumn::make('updated_at')->label('Last changed')->date('j M Y')->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(VendorCategory::cases())->mapWithKeys(fn (VendorCategory $category): array => [$category->value => $category->label()])->all()),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (Vendor $record, array $data): Vendor => app(UpdateVendor::class)->handle(Actor::current(), $record, $data)),
            ]);
    }
}
