<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * On a long-running server (a queue worker today, Octane later) the app stays in memory between requests and jobs.
 * Laravel then forgets its "scoped" services between them. These tests do the same and check nothing carries over.
 */

/**
 * Adds the organisation it ran as, in PHP and in the database, to the list of jobs that ran.
 */
class RecordsEachTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Cache::put('jobs-ran-as', [...Cache::get('jobs-ran-as', []), [
            Tenancy::check() ? Tenancy::id() : null,
            tenantSettingsInDatabase()['organisation'],
        ]]);
    }
}

/**
 * What row-level security sees for the next statement on the app's connection.
 *
 * @return array{organisation: string|null, user: string|null}
 */
function tenantSettingsInDatabase(): array
{
    return (array) DB::connection('pgsql')->selectOne(<<<'SQL'
        select nullif(current_setting('app.organisation_id', true), '') as organisation,
               nullif(current_setting('app.user_id', true), '') as "user"
        SQL);
}

it('registers no singletons in app code, so no service of ours outlives a request or job', function (): void {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/->singleton(If)?\(/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('starts the next request with no organisation or signed-in user left over from the last one', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Tenancy::set($organisation);
    $this->actingAs($user);
    $duringTheRequest = tenantSettingsInDatabase();

    app()->forgetScopedInstances();

    expect([$duringTheRequest, Tenancy::check(), Tenancy::userId(), tenantSettingsInDatabase()])->toBe([
        ['organisation' => $organisation->id, 'user' => $user->id],
        false,
        null,
        ['organisation' => null, 'user' => null],
    ]);
});

it('reads the current organisation on a database connection that was opened by an earlier request', function (): void {
    [$acme, $globex] = Organisation::factory()->count(2)->create();
    Tenancy::run($acme, fn () => Department::factory()->create(['name' => 'Acme Finance']));
    Tenancy::run($globex, fn () => Department::factory()->create(['name' => 'Globex Legal']));

    app()->forgetScopedInstances();

    expect(Tenancy::run($globex, fn (): array => Department::query()->pluck('name')->all()))->toBe(['Globex Legal']);
});

it('runs consecutive jobs on one worker each as its own organisation, and the last without one', function (): void {
    config(['queue.default' => 'database']);
    [$acme, $globex] = Organisation::factory()->count(2)->create();
    // Statements, not arrow functions: PendingDispatch only dispatches when it is destroyed.
    Tenancy::run($acme, function (): void {
        RecordsEachTenantJob::dispatch();
    });
    Tenancy::run($globex, function (): void {
        RecordsEachTenantJob::dispatch();
    });
    RecordsEachTenantJob::dispatch();

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

    expect(Cache::get('jobs-ran-as'))->toBe([[$acme->id, $acme->id], [$globex->id, $globex->id], [null, null]]);
});

it('keeps the signed-in user and the database in step inside a copy of the app, as Octane runs each request', function (): void {
    $user = User::factory()->create();
    $app = app();
    $app->forgetScopedInstances();
    Container::setInstance(clone $app);

    try {
        event(new Authenticated('web', $user));

        expect(tenantSettingsInDatabase()['user'])->toBe($user->id);
    } finally {
        Container::setInstance($app);
    }
});

it('starts clean when Octane hands over new work, even if the last request ended badly', function (string $event): void {
    $organisation = Organisation::factory()->create();
    Tenancy::set($organisation);
    Tenancy::setUser(User::factory()->create()->id);

    // Octane isn't installed: its events are objects that carry the app and the copy it made for this work.
    event($event, [(object) ['app' => app(), 'sandbox' => app()]]);

    expect(tenantSettingsInDatabase())->toBe(['organisation' => null, 'user' => null]);
})->with([
    'Laravel\Octane\Events\RequestReceived',
    'Laravel\Octane\Events\TaskReceived',
    'Laravel\Octane\Events\TickReceived',
]);
