<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return list<string> */
function tenantTables(): array
{
    return DB::connection('pgsql_migrator')->table('information_schema.columns')
        ->where('table_schema', 'public')->where('column_name', 'organisation_id')
        ->orderBy('table_name')->pluck('table_name')->all();
}

it('has at least one tenant table', function (): void {
    expect(tenantTables())->toContain('departments');
});

it('forces row-level security with a tenant policy on every tenant table', function (): void {
    $db = DB::connection('pgsql_migrator');

    foreach (tenantTables() as $table) {
        $rls = $db->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass', [$table]);

        expect($rls->relrowsecurity)->toBeTrue("{$table}: RLS not enabled")
            ->and($rls->relforcerowsecurity)->toBeTrue("{$table}: RLS not forced")
            ->and($db->scalar('select count(*) from pg_policies where tablename = ? and policyname = ?', [$table, "{$table}_tenant_isolation"]))
            ->toBe(1, "{$table}: missing {$table}_tenant_isolation policy");
    }
});

it('has a unique (organisation_id, id) index on every tenant table with an id', function (): void {
    $db = DB::connection('pgsql_migrator');
    $withId = $db->table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'id')
        ->pluck('table_name')->all();

    foreach (array_intersect(tenantTables(), $withId) as $table) {
        $found = $db->scalar(<<<'SQL'
            select count(*) from pg_index i
            where i.indrelid = ?::regclass and i.indisunique
              and (select array_agg(a.attname::text order by k.ord)
                   from unnest(i.indkey) with ordinality k(attnum, ord)
                   join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum) = array['organisation_id', 'id']
            SQL, [$table]);

        expect($found)->toBe(1, "{$table}: missing unique (organisation_id, id)");
    }
});

it('links tenant tables to each other only through composite (organisation_id, …) foreign keys', function (): void {
    $tenantTables = tenantTables();

    $foreignKeys = DB::connection('pgsql_migrator')->select(<<<'SQL'
        select c.conname, c.conrelid::regclass::text as source, c.confrelid::regclass::text as target,
               (select array_agg(a.attname::text order by k.ord) from unnest(c.conkey) with ordinality k(attnum, ord)
                  join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum) as columns,
               (select array_agg(a.attname::text order by k.ord) from unnest(c.confkey) with ordinality k(attnum, ord)
                  join pg_attribute a on a.attrelid = c.confrelid and a.attnum = k.attnum) as referenced
        from pg_constraint c where c.contype = 'f'
        SQL);

    $checked = 0;

    foreach ($foreignKeys as $fk) {
        if (! in_array($fk->source, $tenantTables, true) || ! in_array($fk->target, $tenantTables, true)) {
            continue;
        }

        $checked++;
        expect(str_starts_with($fk->columns, '{organisation_id,'))->toBeTrue("{$fk->conname} must start with organisation_id")
            ->and($fk->referenced)->toBe('{organisation_id,id}', "{$fk->conname} must reference (organisation_id, id)");
    }

    expect($checked)->toBeGreaterThan(0);
});

it('rejects a parent department from another organisation at the database', function (): void {
    [$a, $b] = Organisation::factory()->count(2)->create();
    $parentInA = Tenancy::run($a, fn () => Department::factory()->create());

    DB::connection('pgsql_migrator')->table('departments')->insert([
        'id' => (string) Str::uuid7(),
        'organisation_id' => $b->id,
        'parent_id' => $parentInA->id,
        'name' => 'Cross-tenant child',
    ]);
})->throws(QueryException::class, 'foreign key');

/**
 * Laravel and package tables that keep the package's own id type (bigint or string). They are internal and never
 * appear in URLs. Columns that point at our models (morphs, user_id, organisation_id) are still checked.
 *
 * @return list<string>
 */
function packageTables(): array
{
    return [
        'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions', 'migrations', 'password_reset_tokens',
        'imports', 'exports', 'failed_import_rows',
    ];
}

/** @return list<object{table_name: string, column_name: string, data_type: string}> */
function schemaColumns(): array
{
    return DB::connection('pgsql_migrator')->table('information_schema.columns')
        ->where('table_schema', 'public')->orderBy('table_name')->orderBy('ordinal_position')
        ->get(['table_name', 'column_name', 'data_type'])->all();
}

/** @param  list<object{table_name: string, column_name: string, data_type: string}>  $columns
 * @return list<string> */
function describeColumns(array $columns): array
{
    return array_map(fn (object $column): string => "{$column->table_name}.{$column->column_name} ({$column->data_type})", $columns);
}

it('uses uuid for every id and *_id column', function (): void {
    $packageForeignKeys = ['import_id', 'export_id'];

    $offenders = array_filter(schemaColumns(), fn (object $column): bool => $column->data_type !== 'uuid'
        && (($column->column_name === 'id' && ! in_array($column->table_name, packageTables(), true))
            || (str_ends_with($column->column_name, '_id') && ! in_array($column->column_name, $packageForeignKeys, true))));

    expect(describeColumns(array_values($offenders)))->toBe([]);
});

it('names timestamps *_at and stores them with a time zone', function (): void {
    $unixTimestampTables = ['jobs', 'job_batches'];

    $offenders = array_filter(schemaColumns(), function (object $column) use ($unixTimestampTables): bool {
        $isTimestamp = str_starts_with($column->data_type, 'timestamp');

        if (str_ends_with($column->column_name, '_at')) {
            return $column->data_type !== 'timestamp with time zone' && ! in_array($column->table_name, $unixTimestampTables, true);
        }

        return $isTimestamp;
    });

    expect(describeColumns(array_values($offenders)))->toBe([]);
});

it('names plain dates *_on', function (): void {
    $offenders = array_filter(schemaColumns(), fn (object $column): bool => $column->data_type === 'date'
        && ! str_ends_with($column->column_name, '_on'));

    expect(describeColumns(array_values($offenders)))->toBe([]);
});

it('names booleans is_* or has_*', function (): void {
    $offenders = array_filter(schemaColumns(), fn (object $column): bool => $column->data_type === 'boolean'
        && ! in_array($column->table_name, packageTables(), true)
        && ! preg_match('/^(is|has)_/', $column->column_name));

    expect(describeColumns(array_values($offenders)))->toBe([]);
});

it('names tables in the plural, and pivots as singular_singular in alphabetical order', function (): void {
    $tables = DB::connection('pgsql_migrator')->table('pg_tables')->where('schemaname', 'public')->pluck('tablename')->all();

    $isPivot = function (string $table) use ($tables): bool {
        $words = explode('_', $table);

        for ($split = 1; $split < count($words); $split++) {
            $left = implode('_', array_slice($words, 0, $split));
            $right = implode('_', array_slice($words, $split));

            if ($left < $right && in_array(Str::plural($left), $tables, true) && in_array(Str::plural($right), $tables, true)) {
                return true;
            }
        }

        return false;
    };

    $offenders = array_filter($tables, fn (string $table): bool => ! in_array($table, packageTables(), true)
        && Str::plural(Str::singular($table)) !== $table
        && ! $isPivot($table));

    expect(array_values($offenders))->toBe([]);
});

it('uses BelongsToOrganisation on every model whose table has organisation_id', function (): void {
    $tenantTables = tenantTables();

    foreach (appModels() as $model) {
        if (in_array((new $model)->getTable(), $tenantTables, true)) {
            expect(class_uses_recursive($model))->toHaveKey(BelongsToOrganisation::class);
        }
    }
});
