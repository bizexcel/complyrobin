<?php

use App\Billing\BillingServiceProvider;
use App\Core\Access\AccessServiceProvider;
use App\Core\Identity\IdentityServiceProvider;
use App\Core\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    TenancyServiceProvider::class,
    AccessServiceProvider::class,
    IdentityServiceProvider::class,
    BillingServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
