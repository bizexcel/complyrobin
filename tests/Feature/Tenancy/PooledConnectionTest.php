<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Through a real transaction-mode pooler (PgBouncer with one server connection per role), as on Laravel Cloud with
 * DB_POOLING=true. Runs in the pooled CI leg (DB_EXPECT_POOLER=true): see docker/pgbouncer.
 */
beforeEach(function (): void {
    if (! env('DB_EXPECT_POOLER')) {
        $this->markTestSkipped('Runs only through PgBouncer (DB_EXPECT_POOLER=true).');
    }

    [$this->acme, $this->globex] = Organisation::factory()->count(2)->create();
    Tenancy::run($this->acme, fn () => Department::factory()->count(2)->create());
    Tenancy::run($this->globex, fn () => Department::factory()->count(3)->create());
});

/**
 * Another client of the pooler, as another request or worker would be: a plain connection, without the tenant prefix.
 */
function otherPooledClient(): Connection
{
    return DB::connectUsing('other-client', Arr::except(config('database.connections.pgsql'), ['direct', 'name']), force: true);
}

function pooledSetting(Connection $connection): string
{
    return (string) $connection->getPdo()->query("select current_setting('app.organisation_id', true)")->fetchColumn();
}

it('shares one server connection between clients, as a transaction pooler does', function (): void {
    expect(otherPooledClient()->scalar('select pg_backend_pid()'))->toBe(DB::connection('pgsql')->getPdo()->query('select pg_backend_pid()')->fetchColumn());
});

it('leaks a session-level tenant to the next client: the reason tenancy keeps nothing in the session', function (): void {
    otherPooledClient()->getPdo()->exec("select set_config('app.organisation_id', '{$this->globex->id}', false)");

    expect(pooledSetting(DB::connection('pgsql')))->toBe($this->globex->id);

    otherPooledClient()->getPdo()->exec("select set_config('app.organisation_id', '', false)");
});

it('keeps each organisation to its own rows while another client writes its tenant into the shared session mid-request', function (): void {
    $other = otherPooledClient();

    $acme = Tenancy::run($this->acme, function () use ($other): array {
        $other->getPdo()->exec("select set_config('app.organisation_id', '{$this->globex->id}', false)");

        return [Department::query()->count(), DB::transaction(fn (): int => DB::table('departments')->count())];
    });
    $globex = Tenancy::run($this->globex, function () use ($other): array {
        $other->getPdo()->exec("select set_config('app.organisation_id', '{$this->acme->id}', false)");

        return [Department::query()->count(), DB::transaction(fn (): int => DB::table('departments')->count())];
    });

    expect($acme)->toBe([2, 2])
        ->and($globex)->toBe([3, 3])
        ->and(DB::connection('pgsql')->table('departments')->count())->toBe(0);

    $other->getPdo()->exec("select set_config('app.organisation_id', '', false)");
});

it('leaves nothing in the shared session after tenant work', function (): void {
    Tenancy::run($this->acme, fn (): int => Department::query()->count());

    expect(pooledSetting(otherPooledClient()))->toBe('');
});
