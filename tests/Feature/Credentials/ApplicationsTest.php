<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\CreateApplication;
use App\Modules\Credentials\Actions\UpdateApplication;
use App\Modules\Credentials\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->organisation = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $this->editor = memberWithRole($this->organisation, SystemRole::Editor);
    [$this->owner, $this->backup] = Tenancy::run($this->organisation, fn () => Person::factory()->count(2)->create());
});

function createApplication(User $actor, array $data): Application
{
    return Tenancy::run(test()->organisation, fn (): Application => app(CreateApplication::class)->handle($actor, $data));
}

it('adds an application with its owner and backup owner', function (): void {
    $application = createApplication($this->editor, [
        'name' => 'Laravel Cloud', 'criticality' => 'critical', 'owner_id' => $this->owner->id,
        'backup_owner_id' => $this->backup->id, 'console_url' => 'https://cloud.laravel.com',
    ]);

    expect($application)->owner_id->toBe($this->owner->id)->backup_owner_id->toBe($this->backup->id);
});

it('lets only members who manage credentials add applications', function (): void {
    createApplication(memberWithRole($this->organisation, SystemRole::Viewer), ['name' => 'GitHub', 'criticality' => 'high']);
})->throws(AuthorizationException::class);

it('rejects details that are invalid or point outside the organisation', function (string $field, Closure $value): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');

    try {
        createApplication($this->editor, ['name' => 'GitHub', 'criticality' => 'high', 'owner_id' => $this->owner->id, $field => $value($globex)]);
        $this->fail("Accepted the {$field}.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'another organisation\'s owner' => ['owner_id', fn ($globex) => Tenancy::run($globex, fn () => Person::factory()->create()->id)],
    'the owner as their own backup' => ['backup_owner_id', fn () => test()->owner->id],
    'a javascript: link' => ['console_url', fn (): string => 'javascript:alert(1)'],
    'a plain-http link' => ['console_url', fn (): string => 'http://cloud.laravel.com'],
    'an unknown criticality' => ['criticality', fn (): string => 'apocalyptic'],
]);

it('keeps application names unique in the organisation, ignoring case', function (): void {
    createApplication($this->editor, ['name' => 'GitHub', 'criticality' => 'high']);

    createApplication($this->editor, ['name' => 'github', 'criticality' => 'low']);
})->throws(ValidationException::class);

it('lets an application keep its own name when edited', function (): void {
    $application = createApplication($this->editor, ['name' => 'GitHub', 'criticality' => 'high']);

    Tenancy::run($this->organisation, fn () => app(UpdateApplication::class)->handle($this->editor, $application, ['name' => 'GitHub', 'criticality' => 'critical']));

    expect(Tenancy::run($this->organisation, fn () => $application->refresh()->criticality->value))->toBe('critical');
});

it('refuses another organisation\'s owner in the database, too', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Person::factory()->create());

    DB::connection('pgsql_migrator')->table('applications')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $this->organisation->id, 'name' => 'Sneaky',
        'criticality' => 'low', 'owner_id' => $theirs->id,
    ]);
})->throws(QueryException::class, 'foreign key');
