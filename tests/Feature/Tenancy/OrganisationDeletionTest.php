<?php

use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

it('lets the platform permanently delete a populated organisation and every row it owns', function (): void {
    $acme = populatedOrganisation('Acme');
    $globex = populatedOrganisation('Globex');
    $tables = DB::connection('pgsql_migrator')->table('information_schema.columns')
        ->where('table_schema', 'public')->where('column_name', 'organisation_id')->pluck('table_name');

    Tenancy::bypass('test: close an account', fn () => $acme->forceDelete());

    $left = $tables->filter(fn (string $table): bool => DB::connection('pgsql_migrator')->table($table)->where('organisation_id', $acme->id)->exists());

    expect($left->values()->all())->toBe([])
        ->and(DB::connection('pgsql_migrator')->table('organisations')->where('id', $acme->id)->exists())->toBeFalse()
        ->and(DB::connection('pgsql_migrator')->table('people')->where('organisation_id', $globex->id)->exists())->toBeTrue();
});
