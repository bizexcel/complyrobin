<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Resources\Applications\Pages\CreateApplication;
use App\Filament\App\Resources\Applications\Pages\EditApplication;
use App\Filament\App\Resources\Applications\Pages\ListApplications;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Vendor;
use Database\Seeders\VendorSeeder;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator']);
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

it('lists only the current organisation\'s applications', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Application::factory()->count(2)->create());
    $ours = Tenancy::run($this->organisation, fn () => Application::factory()->count(2)->create());

    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ListApplications::class)->assertCanSeeTableRecords($ours)->assertCanNotSeeTableRecords($theirs);
});

it('fills the name and console link from the chosen vendor', function (): void {
    $github = Vendor::query()->where('slug', 'github')->sole();
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(CreateApplication::class)
        ->fillForm(['vendor_id' => $github->id])
        ->assertSchemaStateSet(['name' => 'GitHub', 'console_url' => 'https://github.com/settings/tokens'])
        ->fillForm(['criticality' => 'high'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Application::query()->sole())->name->toBe('GitHub')->vendor_id->toBe($github->id);
});

it('edits and deletes an application', function (): void {
    $application = Tenancy::run($this->organisation, fn () => Application::factory()->create(['name' => 'GitHub']));
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(EditApplication::class, ['record' => $application->getRouteKey()])
        ->fillForm(['notes' => 'Org-wide token for CI.'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction(DeleteAction::class);

    expect(Application::query()->count())->toBe(0);
});

it('lets viewers see applications but not add them', function (): void {
    Tenancy::run($this->organisation, fn () => Application::factory()->create());
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(ListApplications::class)->assertOk()->assertActionHidden('create');
    Livewire::test(CreateApplication::class)->assertForbidden();
});

it('can\'t open another organisation\'s application', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Application::factory()->create());
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(EditApplication::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});
