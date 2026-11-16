<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\Admin\Pages\Staff;
use App\Filament\Admin\Pages\Widgets\StaffChanges;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['name' => 'Nadia Admin', 'platform_role' => PlatformRole::Admin]);
    $this->support = User::factory()->create(['name' => 'Sam Support', 'platform_role' => PlatformRole::Support]);
});

/**
 * Run the callback as the staff member on the staff panel, as RunAsPlatform runs each of its requests.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function asStaff(User $staff, Closure $callback): mixed
{
    test()->actingAs($staff);
    Filament::setCurrentPanel('admin');

    return Tenancy::bypass('test: staff panel', $callback);
}

it('lists ComplyRobin staff and their roles, and no customers', function (): void {
    User::factory()->create(['name' => 'Chris Customer']);

    $this->actingAs($this->admin)->get('/admin/staff')
        ->assertOk()
        ->assertSee('Nadia Admin')
        ->assertSee('Sam Support')
        ->assertDontSee('Chris Customer');
});

it('keeps the Staff page to Admins', function (): void {
    $this->actingAs($this->support)->get('/admin/staff')->assertForbidden();
});

it('adds an existing account to staff by email', function (): void {
    $aminah = User::factory()->create();

    asStaff($this->admin, fn () => Livewire::test(Staff::class)
        ->callAction('addStaff', ['email' => $aminah->email, 'role' => PlatformRole::Support->value])
        ->assertNotified("{$aminah->email} is now Support."));

    expect($aminah->refresh()->platform_role)->toBe(PlatformRole::Support)
        ->and(staffRoleChanges()->sole()->changed_by_id)->toBe($this->admin->id);
});

it('changes another staff member\'s role, then removes them from staff', function (): void {
    asStaff($this->admin, fn () => Livewire::test(Staff::class)
        ->callTableAction('changeRole', $this->support, ['role' => PlatformRole::Admin->value])
        ->assertHasNoTableActionErrors());
    expect($this->support->refresh()->platform_role)->toBe(PlatformRole::Admin);

    asStaff($this->admin, fn () => Livewire::test(Staff::class)->callTableAction('removeStaff', $this->support));
    expect($this->support->refresh()->platform_role)->toBeNull();
});

it('offers no actions on your own row', function (string $action): void {
    asStaff($this->admin, fn () => Livewire::test(Staff::class)->assertTableActionHidden($action, $this->admin));
})->with(['changeRole', 'removeStaff']);

it('says why a change was refused, and changes nothing', function (): void {
    asStaff($this->admin, fn () => Livewire::test(Staff::class)
        ->callAction('addStaff', ['email' => 'nobody@example.com', 'role' => PlatformRole::Admin->value])
        ->assertNotified('No ComplyRobin account uses that email. Ask them to sign up first.'));

    expect(staffRoleChanges())->toBeEmpty();
});

it('shows recent staff changes, including those made from the server console', function (): void {
    $aminah = User::factory()->create(['name' => 'Aminah Rahman']);
    $this->artisan('staff:grant', ['email' => $aminah->email, '--role' => 'support'])->assertSuccessful();
    asStaff($this->admin, fn () => Livewire::test(Staff::class)->callTableAction('changeRole', $aminah, ['role' => PlatformRole::Admin->value]));

    asStaff($this->admin, fn () => Livewire::test(StaffChanges::class)
        ->assertSeeInOrder(['Aminah Rahman', 'Support', 'Admin', 'Nadia Admin'])
        ->assertSeeInOrder(['Aminah Rahman', 'Support', 'Server console']));
});
