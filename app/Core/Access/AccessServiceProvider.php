<?php

namespace App\Core\Access;

use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Models\Permission;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Events\TenantChanged;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and permissions per organisation, on spatie/laravel-permission in teams mode.
 */
class AccessServiceProvider extends ServiceProvider
{
    private const string VERSION_KEY = 'permissions:version';

    public function boot(): void
    {
        // spatie caches every permission with its roles under one key. Row-level security shows each organisation
        // only its own roles, so each organisation needs its own cache entry. The version changes whenever a global
        // permission does, so every organisation reloads.
        Event::listen(function (TenantChanged $event): void {
            $this->followTenant($event->organisationId);
        });

        Permission::saved(fn () => $this->bumpVersion());
        Permission::deleted(fn () => $this->bumpVersion());

        // Roles only exist inside an organisation: outside one (sign-in, creating an organisation) nobody has any.
        // Owners may do everything; everyone else needs a role that grants the ability.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User || ! Tenancy::check()) {
                return null;
            }

            return $user->hasRole(SystemRole::Owner->value) || $user->checkPermissionTo($ability) ? true : null;
        });
    }

    private function followTenant(?string $organisationId): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $version = Cache::get(self::VERSION_KEY, 'v1');

        $registrar->setPermissionsTeamId($organisationId);
        $registrar->cacheKey = $organisationId === null
            ? "permissions:{$version}:none"
            : "org:{$organisationId}:permissions:{$version}";
        $registrar->clearPermissionsCollection();
    }

    private function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, Str::lower(Str::random(8)));
        $this->followTenant(Tenancy::check() ? Tenancy::id() : null);
    }
}
