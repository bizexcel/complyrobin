<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\Organisations\OrganisationSlugs;
use App\Core\Organisations\ReservedSlugs;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\RegisterOrganisation;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('opens a member\'s organisation as the current tenant', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Acme');
    $tenantDuringRequest = null;
    FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, function () use (&$tenantDuringRequest): string {
        $tenantDuringRequest = Tenancy::id();

        return '';
    });

    $this->actingAs($owner)->get('/acme')->assertOk()->assertSee('Acme');

    expect($tenantDuringRequest)->toBe($organisation->id)
        ->and(Tenancy::check())->toBeFalse();
});

it('returns 404 for an organisation the user does not belong to, as for one that does not exist', function (string $slug): void {
    app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');

    $this->actingAs(User::factory()->create())->get("/{$slug}")->assertNotFound();
})->with(['someone else\'s' => 'acme', 'missing' => 'nowhere']);

it('redirects a member from an old slug to the current one', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Acme');
    Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($owner, $organisation, 'acme-group'));

    $this->actingAs($owner)->get('/acme')->assertRedirect('/acme-group');
});

it('does not reveal where an old slug moved to a non-member', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Acme');
    Tenancy::run($organisation, fn () => app(RenameOrganisationSlug::class)->handle($owner, $organisation, 'acme-group'));

    $this->actingAs(User::factory()->create())->get('/acme')->assertNotFound();
});

it('asks a user without an organisation to create one', function (): void {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/new');
});

it('creates an organisation from the registration page and opens it', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    Livewire::test(RegisterOrganisation::class)
        ->fillForm(['name' => 'Acme Berhad'])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect('/acme-berhad');

    expect($user->organisations()->pluck('slug')->all())->toBe(['acme-berhad']);
});

it('reserves the first path segment of every route, so no organisation slug can shadow one', function (): void {
    $segments = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => explode('/', $route->uri())[0])
        ->filter(fn (string $segment): bool => (bool) preg_match(OrganisationSlugs::PATTERN, $segment))
        ->unique()->values();

    expect($segments->reject(fn (string $segment): bool => ReservedSlugs::contains($segment))->all())->toBe([]);
});
