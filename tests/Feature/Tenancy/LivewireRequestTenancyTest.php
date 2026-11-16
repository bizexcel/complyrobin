<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/*
 * After a page loads, every click, sort, search and save is a Livewire update request. Livewire re-runs the panel's
 * persistent middleware for it around an empty response, then handles the component afterwards. These tests put a
 * probe on each panel's pages and send its real update request, as a browser does, to check the tenancy it runs in.
 */

/**
 * Reports what it runs as: the organisation, whether it bypasses row-level security, the connection, and whether a
 * bypass would be allowed.
 */
class TenancyProbe extends Component
{
    public function render(): string
    {
        $state = [
            'organisation' => Tenancy::check() ? Tenancy::id() : null,
            'bypassing' => Tenancy::isBypassing(),
            'connection' => DB::getDefaultConnection(),
            'may_bypass' => rescue(fn (): bool => Tenancy::bypass('test: probe', fn (): bool => true), false, report: false),
        ];

        return '<div data-probe="'.e((string) json_encode($state)).'"></div>';
    }
}

beforeEach(function (): void {
    Livewire::component('tenancy-probe', TenancyProbe::class);
    FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, fn (): string => Blade::render("@livewire('tenancy-probe')"));
});

/**
 * Load the page, then send the probe's Livewire update, as web requests (not the console, which may always bypass).
 * Returns what the probe saw during each, and what was left set once both requests had ended.
 *
 * @return array{page: array<string, mixed>, livewire: array<string, mixed>, after: array<string, mixed>}
 */
function probePageAndLivewireUpdate(string $path): array
{
    $probe = fn (string $html): array => json_decode(html_entity_decode((string) Str::of($html)->match('/data-probe="([^"]*)"/')), true);
    (fn (): false => $this->isRunningInConsole = false)->call(app());

    try {
        $html = (string) test()->get($path)->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snapshots);
        $snapshot = collect($snapshots[1])->map(fn (string $attribute): string => html_entity_decode($attribute))
            ->first(fn (string $json): bool => (json_decode($json, true)['memo']['name'] ?? null) === 'tenancy-probe');

        $update = test()->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => csrf_token()])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]]])
            ->assertOk();

        return [
            'page' => $probe($html),
            'livewire' => $probe((string) $update->json('components.0.effects.html')),
            'after' => ['organisation' => Tenancy::check(), 'bypassing' => Tenancy::isBypassing(), 'connection' => DB::getDefaultConnection()],
        ];
    } finally {
        (fn (): true => $this->isRunningInConsole = true)->call(app());
    }
}

it('runs a customer\'s Livewire requests inside their organisation, as it does the page', function (): void {
    $owner = User::factory()->create();
    $acme = app(CreateOrganisation::class)->handle($owner, 'Acme');
    $this->actingAs($owner);

    $inside = ['organisation' => $acme->id, 'bypassing' => false, 'connection' => 'pgsql', 'may_bypass' => false];

    expect(probePageAndLivewireUpdate("/{$acme->slug}/people"))->toBe([
        'page' => $inside,
        'livewire' => $inside,
        'after' => ['organisation' => false, 'bypassing' => false, 'connection' => 'pgsql'],
    ]);
});

it('runs a staff member\'s Livewire requests across organisations, as it does the page', function (): void {
    $this->actingAs(User::factory()->create(['platform_role' => PlatformRole::Admin]));

    $acrossOrganisations = ['organisation' => null, 'bypassing' => true, 'connection' => 'pgsql_platform', 'may_bypass' => true];

    expect(probePageAndLivewireUpdate('/admin/organisations'))->toBe([
        'page' => $acrossOrganisations,
        'livewire' => $acrossOrganisations,
        'after' => ['organisation' => false, 'bypassing' => false, 'connection' => 'pgsql'],
    ]);
});
