<?php

use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
