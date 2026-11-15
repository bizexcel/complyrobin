<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    #[\Override]
    protected static string $resource = UserResource::class;
}
