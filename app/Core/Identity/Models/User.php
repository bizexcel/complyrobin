<?php

namespace App\Core\Identity\Models;

use App\Core\Access\Concerns\HasOrganisationRoles;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Organisations\Models\Membership;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Concerns\UsesTenancyConnection;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property PlatformRole|null $platform_role Never mass assignable: it opens the staff panel.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasOrganisationRoles, HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, InteractsWithEmailAuthentication, Notifiable, UsesTenancyConnection;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'platform_role' => PlatformRole::class,
        ];
    }

    /**
     * The organisations this user belongs to. Row-level security only shows a user's own memberships, so this reads
     * correctly once the user is signed in (or inside one of the organisations).
     *
     * @return BelongsToMany<Organisation, $this, Membership>
     */
    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class)->using(Membership::class)->withTimestamps();
    }

    /**
     * @return Collection<int, Organisation>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->organisations()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organisation && $this->organisations()->whereKey($tenant->getKey())->exists();
    }

    /**
     * The admin panel is for ComplyRobin staff only. Customers reach the app panel, then only the organisations
     * they belong to (see canAccessTenant()).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->platform_role instanceof PlatformRole,
            default => true,
        };
    }
}
