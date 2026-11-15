<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remembers which organisation it ran as, both in PHP and in the database session.
 */
class RecordsTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Cache::put('job-tenant', [
            'php' => Tenancy::check() ? Tenancy::id() : null,
            'database' => DB::connection('pgsql')->scalar("select nullif(current_setting('app.organisation_id', true), '')"),
        ]);
    }
}

beforeEach(fn () => config(['queue.default' => 'database']));

it('runs a queued job as the organisation it was dispatched in, and clears it afterwards', function (): void {
    $organisation = Organisation::factory()->create();
    // A statement, not an arrow function: PendingDispatch only dispatches when it is destroyed.
    Tenancy::run($organisation, function (): void {
        RecordsTenantJob::dispatch();
    });

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(Cache::get('job-tenant'))->toBe(['php' => $organisation->id, 'database' => $organisation->id])
        ->and(Tenancy::check())->toBeFalse();
});

it('runs a job dispatched outside an organisation without one', function (): void {
    RecordsTenantJob::dispatch();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(Cache::get('job-tenant'))->toBe(['php' => null, 'database' => null]);
});

it('runs scheduled work inside each organisation in turn, skipping deleted ones', function (): void {
    [$acme, $globex, $gone] = Organisation::factory()->count(3)->create();
    Tenancy::bypass('test: delete an organisation', fn () => $gone->delete());
    $seen = [];

    Tenancy::each(function (Organisation $organisation) use (&$seen): void {
        $seen[$organisation->id] = Tenancy::id();
    });

    expect($seen)->toEqual([$acme->id => $acme->id, $globex->id => $globex->id])
        ->and(Tenancy::check())->toBeFalse();
});
