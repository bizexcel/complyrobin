<?php

use App\Core\Audit\Models\Activity;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    $this->actingAs($this->owner);
});

it('records who changed what, from what to what', function (): void {
    Tenancy::run($this->organisation, function (): void {
        $department = Department::factory()->create(['name' => 'Finance']);
        $department->update(['name' => 'Finance & Risk']);
    });

    $entry = Tenancy::run($this->organisation, fn () => Activity::query()
        ->where('subject_type', (new Department)->getMorphClass())->where('event', 'updated')->sole());

    expect($entry)
        ->organisation_id->toBe($this->organisation->id)
        ->causer_id->toBe($this->owner->id)
        ->and($entry->attribute_changes->toArray())->toEqual([
            'attributes' => ['name' => 'Finance & Risk'],
            'old' => ['name' => 'Finance'],
        ]);
});

it('records a slug rename on the organisation\'s trail', function (): void {
    Tenancy::run($this->organisation, fn () => app(RenameOrganisationSlug::class)->handle($this->owner, $this->organisation, 'acme-group'));

    $changes = Tenancy::run($this->organisation, fn () => Activity::query()
        ->where('subject_type', $this->organisation->getMorphClass())->sole()->attribute_changes->toArray());

    expect($changes['old']['slug'])->toBe('acme')
        ->and($changes['attributes']['slug'])->toBe('acme-group');
});

it('never writes hidden attributes such as invitation tokens to the trail', function (): void {
    Notification::fake();
    onTeamPlan($this->organisation);
    $invitation = Tenancy::run($this->organisation, fn () => app(InviteMember::class)->handle($this->owner, 'bob@example.com', 'Editor'));

    $logged = DB::connection('pgsql_platform')->table('activities')->where('subject_id', $invitation->id)->value('attribute_changes');

    expect($logged)->toContain('bob@example.com')
        ->not->toContain($invitation->token)
        ->not->toContain('"token"');
});

it('shows each organisation only its own trail', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($this->organisation, fn () => Department::factory()->create());

    expect(Tenancy::run($globex, fn () => DB::table('activities')->where('subject_type', (new Department)->getMorphClass())->count()))->toBe(0)
        ->and(Tenancy::run($this->organisation, fn () => DB::table('activities')->where('subject_type', (new Department)->getMorphClass())->count()))->toBe(1);
});

it('keeps the trail append-only for the app', function (string $statement): void {
    Tenancy::run($this->organisation, fn () => Department::factory()->create());

    Tenancy::run($this->organisation, fn () => DB::statement($statement));
})->with([
    'update' => "update activities set description = 'tampered'",
    'delete' => 'delete from activities',
])->throws(QueryException::class, 'permission denied');

it('prunes entries older than the retention period, and only those', function (): void {
    Tenancy::run($this->organisation, fn () => Department::factory()->count(2)->create());
    $oldest = DB::connection('pgsql_platform')->table('activities')->where('subject_type', (new Department)->getMorphClass())->value('id');
    DB::connection('pgsql_platform')->table('activities')->where('id', $oldest)->update(['created_at' => now()->subDays(731)]);
    $before = DB::connection('pgsql_platform')->table('activities')->count();

    $this->artisan('audit:prune')->assertSuccessful();

    expect(DB::connection('pgsql_platform')->table('activities')->count())->toBe($before - 1)
        ->and(DB::connection('pgsql_platform')->table('activities')->where('id', $oldest)->exists())->toBeFalse();
});

it('puts a change platform staff make on the trail of the organisation it concerns', function (): void {
    Tenancy::bypass('test: staff rename', fn () => $this->organisation->update(['name' => 'Acme Group']));

    $entry = Tenancy::run($this->organisation, fn () => Activity::query()
        ->where('subject_type', $this->organisation->getMorphClass())->sole());

    expect($entry->attribute_changes['attributes']['name'])->toBe('Acme Group');
});
