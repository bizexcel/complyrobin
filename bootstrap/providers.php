<?php

use App\Core\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    TenancyServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
