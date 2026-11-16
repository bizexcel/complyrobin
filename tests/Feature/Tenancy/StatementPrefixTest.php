<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Facades\Tenancy;
use App\Core\Tenancy\TenantConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The tenant connection sends the organisation with every statement instead of keeping it in the database session.
 * These pin how that behaves on this PHP and Postgres: results must be the real query's, and nothing may linger.
 */
beforeEach(function (): void {
    [$this->acme, $this->globex] = Organisation::factory()->count(2)->create();
    Tenancy::run($this->acme, fn () => Department::factory()->count(2)->create());
    Tenancy::run($this->globex, fn () => Department::factory()->count(3)->create());
});

function sessionTenant(): string
{
    // Straight through PDO, without the prefix: what the database session itself holds.
    return (string) DB::connection('pgsql')->getPdo()->query("select current_setting('app.organisation_id', true)")->fetchColumn();
}

it('is the connection the app uses', function (): void {
    expect(DB::connection('pgsql'))->toBeInstanceOf(TenantConnection::class)
        ->and(DB::connection('pgsql_platform'))->not->toBeInstanceOf(TenantConnection::class)
        ->and(DB::connection('pgsql_migrator'))->not->toBeInstanceOf(TenantConnection::class);
});

it('sets the tenant for each statement only, never in the database session', function (): void {
    Tenancy::run($this->acme, function (): void {
        expect(DB::scalar("select current_setting('app.organisation_id', true)"))->toBe($this->acme->id)
            ->and(DB::table('departments')->count())->toBe(2)
            ->and(sessionTenant())->toBe('');
    });

    expect(DB::connection('pgsql')->table('departments')->count())->toBe(0);
});

it('overrides whatever a session holds, even with no tenant set', function (): void {
    DB::connection('pgsql')->getPdo()->exec("select set_config('app.organisation_id', '{$this->globex->id}', false)");

    expect(DB::connection('pgsql')->table('departments')->count())->toBe(0)
        ->and(Tenancy::run($this->acme, fn (): int => DB::table('departments')->count()))->toBe(2);

    DB::connection('pgsql')->getPdo()->exec("select set_config('app.organisation_id', '', false)");
});

it('returns the real query\'s rows, counts and errors', function (): void {
    Tenancy::run($this->acme, function (): void {
        $id = (string) Str::uuid7();
        DB::table('departments')->insert(['id' => $id, 'organisation_id' => $this->acme->id, 'name' => 'Ops', 'created_at' => now(), 'updated_at' => now()]);

        expect(DB::table('departments')->where('organisation_id', $this->acme->id)->update(['description' => 'x']))->toBe(3)
            ->and(DB::selectOne('select ? as word, ?::int as number, ? as flag', ['hello', 5, true]))
            ->toEqual((object) ['word' => 'hello', 'number' => 5, 'flag' => true])
            ->and(iterator_count(DB::table('departments')->orderBy('id')->cursor()))->toBe(3)
            ->and(DB::unprepared("update departments set description = 'y' where name = 'Ops'"))->toBeTrue()
            ->and(DB::table('departments')->where('description', 'y')->count())->toBe(1);

        try {
            DB::table('departments')->insert(['id' => $id, 'organisation_id' => $this->acme->id, 'name' => 'Again']);
            $this->fail('A duplicate key was accepted.');
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('23505')->and($exception->getSql())->not->toContain('set_config');
        }
    });
});

it('keeps the tenant inside transactions and after rolling back to a savepoint', function (): void {
    $counts = Tenancy::run($this->acme, fn (): array => DB::transaction(function (): array {
        $before = DB::table('departments')->count();

        try {
            DB::transaction(function (): void {
                Department::factory()->create();

                throw new RuntimeException('inner');
            });
        } catch (RuntimeException) {
        }

        return [$before, DB::table('departments')->count(), DB::scalar("select current_setting('app.organisation_id', true)")];
    }));

    expect($counts)->toBe([2, 2, $this->acme->id]);
});

it('fails closed if the tenant connection ever uses native prepares', function (): void {
    config(['database.connections.pgsql.options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
    DB::purge('pgsql');

    try {
        Tenancy::run($this->acme, fn (): int => DB::table('departments')->count());
        $this->fail('A query ran without the tenant prefix.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('cannot insert multiple commands');
    } finally {
        config(['database.connections.pgsql.options' => [PDO::ATTR_EMULATE_PREPARES => true]]);
        DB::purge('pgsql');
    }
});
