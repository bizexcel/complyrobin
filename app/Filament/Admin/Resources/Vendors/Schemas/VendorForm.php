<?php

namespace App\Filament\Admin\Resources\Vendors\Schemas;

use App\Modules\Credentials\Enums\VendorCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(60)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->helperText('Lower-case letters, numbers and hyphens, such as laravel-cloud. It can\'t be changed later.')
                    ->disabledOn('edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                Select::make('category')
                    ->options(collect(VendorCategory::cases())->mapWithKeys(fn (VendorCategory $category): array => [$category->value => $category->label()])->all())
                    ->required(),
                TextInput::make('rotation_days')
                    ->label('Suggested rotation (days)')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1095)
                    ->default(90)
                    ->required()
                    ->helperText('Offered as the interval when someone adds a credential for this vendor.'),
                TextInput::make('website_url')->label('Website')->url()->required()->maxLength(255)->placeholder('https://'),
                TextInput::make('rotation_url')
                    ->label('Where to rotate')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://')
                    ->helperText('The page where people create and revoke keys. Leave empty if there isn\'t one.'),
            ]);
    }
}
