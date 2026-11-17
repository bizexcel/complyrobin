<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Modules\Credentials\Actions\CreateVendor;
use App\Modules\Credentials\Actions\UpdateVendor;
use App\Modules\Credentials\Enums\VendorCategory;
use App\Modules\Credentials\Models\Vendor;
use Database\Seeders\VendorSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator'])->assertSuccessful();
    $this->admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $this->support = User::factory()->create(['platform_role' => PlatformRole::Support]);
    $this->github = Vendor::query()->where('slug', 'github')->sole();
});

/**
 * @return array<string, mixed>
 */
function newVendor(array $overrides = []): array
{
    return [
        'name' => 'Linear',
        'slug' => 'linear',
        'category' => VendorCategory::Productivity->value,
        'rotation_days' => 180,
        'website_url' => 'https://linear.app',
        'rotation_url' => 'https://linear.app/settings/api',
        ...$overrides,
    ];
}

it('shows staff the catalogue', function (PlatformRole $role): void {
    $this->actingAs(User::factory()->create(['platform_role' => $role]))->get('/admin/vendors')
        ->assertOk()->assertSee('GitHub')->assertSee('Cloudflare');
})->with([PlatformRole::Admin, PlatformRole::Support]);

it('lets an Admin add a vendor that every organisation can then pick', function (): void {
    asStaff($this->admin, fn () => Livewire::test(ListVendors::class)
        ->callAction('create', newVendor())
        ->assertHasNoActionErrors());

    $vendor = Vendor::query()->where('slug', 'linear')->sole();

    expect($vendor->only(['name', 'category', 'rotation_days', 'rotation_url']))->toBe([
        'name' => 'Linear',
        'category' => VendorCategory::Productivity,
        'rotation_days' => 180,
        'rotation_url' => 'https://linear.app/settings/api',
    ]);
});

it('lets an Admin edit a vendor, keeping its slug, and the next deploy keeps the edit', function (): void {
    asStaff($this->admin, fn () => Livewire::test(ListVendors::class)
        ->callTableAction('edit', $this->github, newVendor(['name' => 'GitHub', 'slug' => 'changed', 'category' => VendorCategory::SourceControl->value, 'website_url' => 'https://github.com', 'rotation_days' => 30, 'rotation_url' => 'https://github.com/settings/personal-access-tokens']))
        ->assertHasNoTableActionErrors());

    $this->artisan('db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator'])->assertSuccessful();

    expect($this->github->refresh()->only(['slug', 'rotation_days', 'rotation_url']))->toBe([
        'slug' => 'github',
        'rotation_days' => 30,
        'rotation_url' => 'https://github.com/settings/personal-access-tokens',
    ]);
});

it('lets Support look but not change the catalogue', function (): void {
    asStaff($this->support, fn () => Livewire::test(ListVendors::class)
        ->assertActionHidden('create')
        ->assertTableActionHidden('edit', $this->github));

    expect(fn () => app(CreateVendor::class)->handle($this->support, newVendor()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateVendor::class)->handle($this->support, $this->github, newVendor(['name' => 'Evil'])))->toThrow(AuthorizationException::class)
        ->and($this->github->refresh()->name)->toBe('GitHub');
});

it('refuses a vendor with a bad or taken value', function (array $overrides, string $field): void {
    try {
        app(CreateVendor::class)->handle($this->admin, newVendor($overrides));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(Vendor::query()->where('slug', 'linear')->exists())->toBeFalse();
})->with([
    'plain http website' => [['website_url' => 'http://linear.app'], 'website_url'],
    'javascript rotation link' => [['rotation_url' => 'javascript:alert(1)'], 'rotation_url'],
    'slug taken' => [['slug' => 'github'], 'slug'],
    'slug with capitals' => [['slug' => 'Linear App'], 'slug'],
    'name taken' => [['name' => 'GitHub'], 'name'],
    'interval of zero' => [['rotation_days' => 0], 'rotation_days'],
    'unknown category' => [['category' => 'gambling'], 'category'],
]);
