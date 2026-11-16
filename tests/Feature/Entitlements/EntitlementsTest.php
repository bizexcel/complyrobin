<?php

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    [$this->acme, $this->globex] = Organisation::factory()->count(2)->create();
});

function hasModule(Organisation $organisation, Module $module): bool
{
    return Tenancy::run($organisation, fn (): bool => app(Entitlements::class)->has($module));
}

it('gives an organisation the modules staff grant it, and only those', function (): void {
    app(GrantModule::class)->handle($this->staff, $this->acme, Module::Credentials, now()->subDay());

    expect(hasModule($this->acme, Module::Credentials))->toBeTrue()
        ->and(hasModule($this->acme, Module::Isms))->toBeFalse()
        ->and(hasModule($this->globex, Module::Credentials))->toBeFalse();
});

it('honours when a grant starts and ends', function (string $startsAt, ?string $endsAt, bool $isActive): void {
    app(GrantModule::class)->handle($this->staff, $this->acme, Module::Credentials, now()->modify($startsAt), $endsAt === null ? null : now()->modify($endsAt));

    expect(hasModule($this->acme, Module::Credentials))->toBe($isActive);
})->with([
    'started, open-ended' => ['-1 day', null, true],
    'started, ends later' => ['-1 day', '+1 day', true],
    'not started yet' => ['+1 day', null, false],
    'ended' => ['-2 days', '-1 day', false],
]);

it('shares a root organisation\'s modules with its sub-organisations', function (): void {
    $penang = Organisation::factory()->create(['parent_id' => $this->acme->id, 'root_id' => $this->acme->id]);

    app(GrantModule::class)->handle($this->staff, $this->acme, Module::Isms, now()->subDay());

    expect(hasModule($penang, Module::Isms))->toBeTrue();
});

it('sees a new grant straight away, despite the cache', function (): void {
    expect(hasModule($this->acme, Module::Bcms))->toBeFalse();

    app(GrantModule::class)->handle($this->staff, $this->acme, Module::Bcms, now()->subMinute());

    expect(hasModule($this->acme, Module::Bcms))->toBeTrue();
});

it('lets only a platform Admin grant modules', function (?PlatformRole $role): void {
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => $role]), $this->acme, Module::Credentials, now());
})->with(['Support' => PlatformRole::Support, 'a customer' => null])->throws(AuthorizationException::class);

it('stops an organisation granting itself a module in the database', function (): void {
    Tenancy::run($this->acme, fn () => DB::table('module_grants')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $this->acme->id, 'module' => 'isms', 'starts_at' => now(),
    ]));
})->throws(QueryException::class, 'permission denied');
