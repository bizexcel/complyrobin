<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Actions\CreatePerson;
use App\Core\People\Models\Department;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $this->editor = memberWithRole($this->organisation, SystemRole::Editor);
});

it('adds a person to the current organisation', function (): void {
    $department = Tenancy::run($this->organisation, fn () => Department::factory()->create());

    $person = Tenancy::run($this->organisation, fn () => app(CreatePerson::class)->handle($this->editor, [
        'name' => 'Siti Rahman', 'email' => 'siti@example.com', 'phone' => '+60123456789', 'department_id' => $department->id,
    ]));

    expect(Tenancy::run($this->organisation, fn () => $person->refresh()))
        ->organisation_id->toBe($this->organisation->id)
        ->department_id->toBe($department->id);
});

it('lets only members who manage people add them', function (): void {
    $viewer = memberWithRole($this->organisation, SystemRole::Viewer);

    Tenancy::run($this->organisation, fn () => app(CreatePerson::class)->handle($viewer, ['name' => 'Siti Rahman']));
})->throws(AuthorizationException::class);

it('rejects details that are invalid or point outside the organisation', function (string $field, Closure $value): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');

    try {
        Tenancy::run($this->organisation, fn () => app(CreatePerson::class)->handle($this->editor, ['name' => 'Siti Rahman', $field => $value($globex)]));
        $this->fail("Accepted the {$field}.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field)
            ->and(Tenancy::run($this->organisation, fn () => Person::query()->count()))->toBe(0);
    }
})->with([
    'another organisation\'s department' => ['department_id', fn ($globex) => Tenancy::run($globex, fn () => Department::factory()->create()->id)],
    'a user who isn\'t a member' => ['user_id', fn () => User::factory()->create()->id],
    'a phone number that isn\'t one' => ['phone', fn (): string => '12345'],
    'an email that isn\'t one' => ['email', fn (): string => 'siti'],
]);
