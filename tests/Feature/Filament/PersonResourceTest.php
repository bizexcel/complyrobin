<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Resources\People\Pages\CreatePerson;
use App\Filament\App\Resources\People\Pages\EditPerson;
use App\Filament\App\Resources\People\Pages\ListPeople;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

it('lists only the current organisation\'s people', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Person::factory()->count(2)->create());
    $ours = Tenancy::run($this->organisation, fn () => Person::factory()->count(2)->create());

    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ListPeople::class)
        ->assertCanSeeTableRecords($ours)
        ->assertCanNotSeeTableRecords($theirs);
});

it('adds, edits and deletes a person', function (): void {
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(CreatePerson::class)
        ->fillForm(['name' => 'Siti Rahman', 'email' => 'siti@example.com'])
        ->call('create')
        ->assertHasNoFormErrors();
    $person = Person::query()->sole();

    Livewire::test(EditPerson::class, ['record' => $person->getRouteKey()])
        ->fillForm(['job_title' => 'IT Manager'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction(DeleteAction::class);

    expect(Person::query()->count())->toBe(0)
        ->and(Person::withTrashed()->sole()->job_title)->toBe('IT Manager');
});

it('lets viewers see people but not add them', function (): void {
    Tenancy::run($this->organisation, fn () => Person::factory()->create());
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(ListPeople::class)->assertOk()->assertActionHidden('create');
    Livewire::test(CreatePerson::class)->assertForbidden();
});

it('can\'t open another organisation\'s person', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Person::factory()->create());
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(EditPerson::class, ['record' => $theirs->getRouteKey()])->assertNotFound();
});
