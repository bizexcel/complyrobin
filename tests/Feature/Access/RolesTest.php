<?php

use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('gives each new organisation the system roles', function (): void {
    $organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');

    expect(Tenancy::run($organisation, fn () => Role::query()->orderBy('name')->pluck('name')->all()))
        ->toBe(['Admin', 'Approver', 'Editor', 'Owner', 'Viewer']);
});

it('makes the creator Owner of the new organisation only', function (): void {
    $alice = User::factory()->create();
    $acme = app(CreateOrganisation::class)->handle($alice, 'Acme');
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($globex, fn () => $globex->users()->attach($alice));

    expect(Tenancy::run($acme, fn (): bool => $alice->hasRole(SystemRole::Owner->value) && $alice->can(Ability::ManageMembers->value)))->toBeTrue()
        ->and(Tenancy::run($globex, fn (): bool => $alice->hasRole(SystemRole::Owner->value) || $alice->can(Ability::ManageMembers->value)))->toBeFalse();
});

it('checks a user\'s permissions against the current organisation\'s roles, even when cached', function (): void {
    $alice = User::factory()->create();
    $acme = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($acme, function () use ($acme, $alice): void {
        $acme->users()->attach($alice);
        $alice->assignRole(SystemRole::Admin->value);
    });
    Tenancy::run($globex, function () use ($globex, $alice): void {
        $globex->users()->attach($alice);
        $alice->assignRole(SystemRole::Viewer->value);
    });

    // Globex first, so a cache shared between organisations would be built without Acme's roles.
    expect(Tenancy::run($globex, fn (): bool => $alice->can(Ability::ManageMembers->value)))->toBeFalse()
        ->and(Tenancy::run($acme, fn (): bool => $alice->fresh()->can(Ability::ManageMembers->value)))->toBeTrue();
});

it('refuses to give a member a role from another organisation', function (): void {
    $alice = User::factory()->create();
    $acme = app(CreateOrganisation::class)->handle($alice, 'Acme');
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $globexOwnerRole = Tenancy::run($globex, fn () => Role::query()->where('name', SystemRole::Owner->value)->value('id'));

    Tenancy::run($acme, fn () => DB::table('model_has_roles')->insert([
        'organisation_id' => $acme->id, 'role_id' => $globexOwnerRole, 'model_type' => $alice->getMorphClass(), 'model_id' => $alice->id,
    ]));
})->throws(QueryException::class, 'foreign key');

it('gives existing organisations a new ability when roles are synced', function (): void {
    $organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    Tenancy::run($organisation, fn (): Spatie\Permission\Models\Role => Role::findByName(SystemRole::Admin->value)->revokePermissionTo(Ability::ManageTasks->value));

    $this->artisan('access:sync-system-roles')->assertSuccessful();

    expect(Tenancy::run($organisation, fn (): bool => Role::findByName(SystemRole::Admin->value)->hasPermissionTo(Ability::ManageTasks->value)))->toBeTrue();
});

it('never removes or duplicates an ability when roles are synced', function (): void {
    $organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    Tenancy::run($organisation, fn (): Spatie\Permission\Models\Role => Role::findByName(SystemRole::Viewer->value)->givePermissionTo(Ability::ManagePeople->value));
    $links = fn (): int => Tenancy::run($organisation, fn (): int => DB::table('role_has_permissions')->count());
    $before = $links();

    $this->artisan('access:sync-system-roles')->assertSuccessful();
    $this->artisan('access:sync-system-roles')->assertSuccessful();

    expect($links())->toBe($before)
        ->and(Tenancy::run($organisation, fn (): bool => Role::findByName(SystemRole::Viewer->value)->hasPermissionTo(Ability::ManagePeople->value)))->toBeTrue();
});
