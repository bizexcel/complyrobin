<?php

use App\Core\Access\Enums\Ability;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Numbering\Numbering;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\People\Models\Person;
use App\Core\Tasks\Actions\CreateTask;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * An organisation with at least one row in every tenant table, made through the real actions where they exist.
 * A new tenant table fails the coverage test below until it gets a row here.
 */
function populatedOrganisation(string $name): Organisation
{
    Notification::fake();
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, $name);
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    app(GrantModule::class)->handle($staff, $organisation, Module::Credentials, now()->subDay());

    test()->actingAs($owner);

    Tenancy::run($organisation, function () use ($owner, $organisation): void {
        $owner->givePermissionTo(Ability::ManagePeople->value);
        app(InviteMember::class)->handle($owner, Str::lower(Str::random(8)).'@example.com', 'Editor');
        $department = Department::factory()->create();
        $person = Person::factory()->create(['department_id' => $department->id]);
        app(CreateTask::class)->handle($owner, ['title' => 'Rotate a key', 'assignee_id' => $person->id], $person);
        app(Numbering::class)->next('credential', 'CRD');

        DB::connection('pgsql_migrator')->table('media')->insert([
            'id' => (string) Str::uuid7(), 'organisation_id' => $organisation->id, 'uuid' => (string) Str::uuid7(),
            'model_type' => Person::class, 'model_id' => $person->id, 'collection_name' => 'evidence', 'name' => 'evidence',
            'file_name' => 'evidence.pdf', 'disk' => 'local', 'size' => 1, 'manipulations' => '[]',
            'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]',
        ]);
    });

    return $organisation;
}

/** @return list<string> */
function isolatedTables(): array
{
    return DB::connection('pgsql_migrator')->table('information_schema.columns')
        ->where('table_schema', 'public')->where('column_name', 'organisation_id')
        ->orderBy('table_name')->pluck('table_name')->all();
}

beforeEach(function (): void {
    $this->acme = populatedOrganisation('Acme');
    $this->globex = populatedOrganisation('Globex');
});

it('has rows for every tenant table in the fixture, so every table is isolation-tested', function (): void {
    $empty = array_filter(isolatedTables(), fn (string $table): bool => Tenancy::run(
        $this->acme,
        fn (): int => DB::table($table)->where('organisation_id', $this->acme->id)->count(),
    ) === 0);

    expect(array_values($empty))->toBe([]);
});

it('hides every tenant table\'s rows from other organisations', function (): void {
    $leaks = array_filter(isolatedTables(), fn (string $table): bool => Tenancy::run(
        $this->globex,
        fn (): int => DB::table($table)->where('organisation_id', $this->acme->id)->count(),
    ) > 0);

    expect(array_values($leaks))->toBe([]);
});

it('stops other organisations changing or deleting any tenant table\'s rows', function (string $statement): void {
    $changed = [];

    foreach (isolatedTables() as $table) {
        try {
            $affected = Tenancy::run($this->globex, fn (): int => DB::affectingStatement(
                sprintf($statement, $table),
                [$this->acme->id],
            ));
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('permission denied');
            $affected = 0;
        }

        if ($affected > 0) {
            $changed[] = $table;
        }
    }

    expect($changed)->toBe([]);
})->with([
    'update' => 'update "%s" set organisation_id = organisation_id where organisation_id = ?',
    'delete' => 'delete from "%s" where organisation_id = ?',
]);

it('shows nothing at all without a current organisation', function (): void {
    auth()->logout();

    $visible = array_filter(isolatedTables(), fn (string $table): bool => DB::table($table)->count() > 0);

    expect(array_values($visible))->toBe([]);
});
