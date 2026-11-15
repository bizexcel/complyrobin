<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Exceptions\TenantMismatch;
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

it('refuses to change or delete an organisation from outside it instead of silently doing nothing', function (Closure $change): void {
    [$acme, $globex] = Organisation::factory()->count(2)->create();

    Tenancy::run($acme, fn () => $change($globex));
})->with([
    'rename another organisation' => fn (Organisation $other) => $other->update(['name' => 'Hijacked']),
    'delete another organisation' => fn (Organisation $other) => $other->delete(),
])->throws(TenantMismatch::class);

it('refuses to delete even the current organisation outside a platform bypass', function (): void {
    $acme = Organisation::factory()->create();

    Tenancy::run($acme, fn () => $acme->delete());
})->throws(TenantMismatch::class);
