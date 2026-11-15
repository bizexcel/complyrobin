<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    [$this->a, $this->b] = Organisation::factory()->count(2)->create();

    Tenancy::run($this->a, fn () => Department::factory()->count(2)->create());
    Tenancy::run($this->b, fn () => Department::factory()->create());
});

it('shows the tenant role nothing when no tenant is set', function (): void {
    expect(DB::connection('pgsql')->scalar('select count(*) from departments'))->toBe(0);
});

it('shows the tenant role only the current organisation', function (): void {
    Tenancy::set($this->a);

    expect(DB::connection('pgsql')->scalar('select count(*) from departments'))->toBe(2);
});

it('rejects a query-builder insert for another organisation', function (): void {
    Tenancy::set($this->a);

    DB::connection('pgsql')->table('departments')->insert([
        'id' => (string) Str::uuid7(),
        'organisation_id' => $this->b->id,
        'name' => 'Smuggled',
    ]);
})->throws(QueryException::class, 'row-level security');
