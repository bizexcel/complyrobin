<?php

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Actor;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Modules\Credentials\Actions\CreateVendor;
use App\Modules\Credentials\Models\Vendor;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    #[\Override]
    protected static string $resource = VendorResource::class;

    public function getSubheading(): string
    {
        return 'Organisations pick these when they add an application. A change shows to everyone at once. Credentials already in a register keep their own rotation interval.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New vendor')
                ->visible(fn (): bool => Actor::isAdmin())
                ->using(fn (array $data): Vendor => app(CreateVendor::class)->handle(Actor::current(), $data)),
        ];
    }
}
