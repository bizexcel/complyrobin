<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Resources\Credentials\Pages\CreateCredential;
use App\Filament\App\Resources\Credentials\Pages\ListCredentials;
use App\Filament\App\Resources\Credentials\Pages\ViewCredential;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    $this->application = Tenancy::run($this->organisation, fn () => Application::factory()->create());
});

it('lists only the current organisation\'s credentials and shows how many the plan allows', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Credential::factory()->count(2)->create());
    $ours = Tenancy::run($this->organisation, fn () => Credential::factory()->count(3)->create(['application_id' => $this->application->id]));

    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ListCredentials::class)
        ->assertCanSeeTableRecords($ours)
        ->assertCanNotSeeTableRecords($theirs)
        ->assertSee('3 of 10 credentials on Free');
});

it('filters overdue credentials', function (): void {
    [$overdue, $fine] = Tenancy::run($this->organisation, fn (): array => [
        Credential::factory()->overdueBy(2)->create(['application_id' => $this->application->id]),
        Credential::factory()->dueIn(60)->create(['application_id' => $this->application->id]),
    ]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ListCredentials::class)
        ->filterTable('status', 'overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$fine]);
});

it('adds a credential from the form', function (): void {
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(CreateCredential::class)
        ->fillForm(['application_id' => $this->application->id, 'name' => 'CI token', 'type' => 'api_token', 'environment' => 'production', 'rotation_days' => 90])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Credential::query()->sole()->reference)->toBe('CRD-0001');
});

it('revokes a credential from its page', function (): void {
    $credential = Tenancy::run($this->organisation, fn () => Credential::factory()->create(['application_id' => $this->application->id]));
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])->callAction('revoke');

    expect($credential->refresh()->revoked_at)->not->toBeNull();
});

it('lets viewers see credentials but not add or revoke them', function (): void {
    $credential = Tenancy::run($this->organisation, fn () => Credential::factory()->create(['application_id' => $this->application->id]));
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(ListCredentials::class)->assertOk()->assertActionHidden('create');
    Livewire::test(ViewCredential::class, ['record' => $credential->getRouteKey()])->assertActionHidden('revoke');
    Livewire::test(CreateCredential::class)->assertForbidden();
});
