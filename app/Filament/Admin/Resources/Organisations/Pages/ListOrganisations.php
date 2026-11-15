<?php

namespace App\Filament\Admin\Resources\Organisations\Pages;

use App\Filament\Admin\Resources\Organisations\OrganisationResource;
use Filament\Resources\Pages\ListRecords;

class ListOrganisations extends ListRecords
{
    #[\Override]
    protected static string $resource = OrganisationResource::class;
}
