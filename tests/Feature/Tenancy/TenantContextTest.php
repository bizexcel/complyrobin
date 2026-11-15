<?php

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

function currentSetting(): ?string
{
    return DB::connection('pgsql')->scalar("select nullif(current_setting('app.organisation_id', true), '')");
}

it('rejects invalid ids', function (string $id): void {
    Tenancy::set($id);
})->with(['', 'abc', '123'])->throws(InvalidArgumentException::class);

it('mirrors the tenant into the database session', function (): void {
    $a = Organisation::factory()->create();

    Tenancy::set($a);
    expect(currentSetting())->toBe($a->id);

    Tenancy::forget();
    expect(currentSetting())->toBeNull()
        ->and(Tenancy::check())->toBeFalse();
});

it('restores the previous tenant after a nested run that throws', function (): void {
    [$a, $b] = Organisation::factory()->count(2)->create();

    Tenancy::run($a, function () use ($a, $b): void {
        try {
            Tenancy::run($b, fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        expect(Tenancy::id())->toBe($a->id)
            ->and(currentSetting())->toBe($a->id);
    });

    expect(Tenancy::check())->toBeFalse()
        ->and(currentSetting())->toBeNull();
});

it('restores the connection after a bypass that throws', function (): void {
    try {
        Tenancy::bypass('test', fn (): never => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(DB::getDefaultConnection())->toBe('pgsql')
        ->and(Tenancy::isBypassing())->toBeFalse();
});

it('re-applies the setting after reconnect', function (): void {
    $a = Organisation::factory()->create();

    Tenancy::set($a);
    DB::reconnect('pgsql');

    expect(currentSetting())->toBe($a->id);
});

it('writes a model loaded as the tenant through the platform role inside a bypass, and back', function (): void {
    [$acme, $globex] = Organisation::factory()->count(2)->create();
    $loadedAsTenant = Organisation::query()->findOrFail($globex->id);
    $loadedInBypass = Tenancy::bypass('test: load', fn () => Organisation::query()->findOrFail($globex->id));

    Tenancy::bypass('test: rename', fn () => $loadedAsTenant->update(['name' => 'Renamed by staff']));

    expect(DB::connection('pgsql_migrator')->table('organisations')->where('id', $globex->id)->value('name'))->toBe('Renamed by staff')
        ->and(fn () => Tenancy::run($acme, fn () => $loadedInBypass->update(['name' => 'Hijacked'])))->toThrow(TenantMismatch::class);
});
