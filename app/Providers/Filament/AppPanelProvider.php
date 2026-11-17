<?php

namespace App\Providers\Filament;

use App\Core\Organisations\Models\Organisation;
use App\Filament\App\Pages\OrganisationSettings;
use App\Filament\App\Pages\RegisterOrganisation;
use App\Filament\App\Tenancy\ResolveOrganisation;
use App\Filament\App\Tenancy\SetCurrentOrganisation;
use App\Filament\Brand;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login()
            ->databaseNotifications()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable(), EmailAuthentication::make()], isRequired: true)
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->brandName('ComplyRobin')
            ->brandLogo(fn (): string => Brand::logo())
            ->darkModeBrandLogo(fn (): string => Brand::logo(dark: true))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => asset('favicon.svg'))
            ->font(Brand::FONT)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.brand.head'))
            ->tenant(Organisation::class, slugAttribute: 'slug')
            ->resolveTenantUsing(fn (string $key): Organisation => app(ResolveOrganisation::class)($key))
            ->tenantRegistration(RegisterOrganisation::class)
            ->tenantProfile(OrganisationSettings::class)
            ->tenantMiddleware([SetCurrentOrganisation::class], isPersistent: true)
            ->colors(Brand::colors())
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
