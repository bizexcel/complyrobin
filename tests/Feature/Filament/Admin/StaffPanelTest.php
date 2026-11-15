<?php

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\Admin\Resources\Organisations\Pages\ListOrganisations;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->staff = User::factory()->create(['platform_role' => PlatformRole::Support]);
    $this->acme = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $this->globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
});

it('shows staff every organisation, working across organisations on the platform connection', function (): void {
    $duringRequest = null;
    FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, function () use (&$duringRequest): string {
        $duringRequest = [Tenancy::isBypassing(), DB::getDefaultConnection()];

        return '';
    });

    $this->actingAs($this->staff)->get('/admin/organisations')->assertOk()->assertSee('Acme')->assertSee('Globex');

    expect($duringRequest)->toBe([true, 'pgsql_platform'])
        ->and(Tenancy::isBypassing())->toBeFalse();
});

it('lets staff grant a module to an organisation', function (): void {
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');

    Tenancy::bypass('test: staff panel', fn () => Livewire::test(ListOrganisations::class)
        ->callTableAction('grantModule', $this->acme, ['module' => Module::Credentials->value, 'starts_at' => now()->subMinute()])
        ->assertHasNoTableActionErrors());

    expect(Tenancy::run($this->acme, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeTrue()
        ->and(Tenancy::run($this->globex, fn (): bool => app(Entitlements::class)->has(Module::Credentials)))->toBeFalse();
});

it('keeps customers out of the staff panel', function (string $path): void {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin', '/admin/organisations', '/admin/users']);
