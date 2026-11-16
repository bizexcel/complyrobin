<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Department;
use App\Core\Tenancy\Exceptions\TenancyBypassDenied;
use App\Core\Tenancy\Exceptions\TenantMismatch;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    [$this->a, $this->b] = Organisation::factory()->count(2)->create();

    Tenancy::run($this->a, fn () => Department::factory()->count(2)->create());
    Tenancy::run($this->b, fn () => Department::factory()->count(3)->create());
});

function dbTenant(): ?string
{
    return DB::connection('pgsql')->scalar("select nullif(current_setting('app.organisation_id', true), '')");
}

it('scopes run() inside bypass() to that organisation', function (): void {
    [$eloquent, $raw] = Tenancy::bypass('loop', fn () => Tenancy::run($this->a, fn (): array => [
        Department::count(),
        DB::table('departments')->count(),
    ]));

    expect($eloquent)->toBe(2)->and($raw)->toBe(2)
        ->and(Tenancy::isBypassing())->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe('pgsql');
});

it('restores bypass after a run() nested inside it', function (): void {
    $after = Tenancy::bypass('loop', function (): int {
        Tenancy::run($this->a, fn (): null => null);

        return Department::count();
    });

    expect($after)->toBe(5);
});

it('stops the tenant role deleting or renaming another organisation', function (): void {
    Tenancy::set($this->a);
    $db = DB::connection('pgsql');

    expect($db->delete('delete from organisations where id = ?', [$this->b->id]))->toBe(0)
        ->and($db->update("update organisations set name = 'pwned' where id = ?", [$this->b->id]))->toBe(0)
        ->and($db->update("update organisations set name = 'Renamed A' where id = ?", [$this->a->id]))->toBe(1);

    Tenancy::forget();
    expect(Tenancy::run($this->b, fn () => Department::count()))->toBe(3);
});

it('keeps the database tenant in step with PHP after a rollback', function (): void {
    Tenancy::set($this->b);

    try {
        DB::transaction(function (): void {
            Tenancy::set($this->a);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(dbTenant())->toBe(Tenancy::id());
});

it('switches tenant without touching the database, so the next statement after a failed transaction runs as the new one', function (): void {
    Tenancy::set($this->b);
    DB::beginTransaction();

    try {
        DB::statement('select 1/0');
    } catch (QueryException) {
    }

    Tenancy::set($this->a);
    DB::rollBack();

    expect(Tenancy::id())->toBe($this->a->id)
        ->and(dbTenant())->toBe($this->a->id)
        ->and(DB::table('departments')->count())->toBe(2);
});

it('refuses bypass inside an open tenant transaction', function (): void {
    DB::transaction(fn () => Tenancy::bypass('x', fn (): int => 1));
})->throws(TenancyBypassDenied::class);

it('refuses to save another organisation\'s model fetched during a bypass', function (): void {
    $bDepartment = Tenancy::bypass('fetch', fn () => Department::where('organisation_id', $this->b->id)->first());

    Tenancy::run($this->a, function () use ($bDepartment): void {
        $bDepartment->name = 'edited-from-A';
        $bDepartment->save();
    });
})->throws(TenantMismatch::class);

it('refuses to delete another organisation\'s model fetched during a bypass', function (): void {
    $bDepartment = Tenancy::bypass('fetch', fn () => Department::where('organisation_id', $this->b->id)->first());

    Tenancy::run($this->a, fn () => $bDepartment->delete());
})->throws(TenantMismatch::class);

it('refuses to move a model to another organisation', function (): void {
    Tenancy::run($this->a, function (): void {
        $department = Department::first();
        $department->organisation_id = $this->b->id;
        $department->save();
    });
})->throws(TenantMismatch::class);

it('still lets a tenant edit and delete its own rows', function (): void {
    Tenancy::run($this->a, function (): void {
        $department = Department::first();
        $department->update(['name' => 'Ops']);
        $department->delete();
    });

    expect(Tenancy::run($this->a, fn () => Department::count()))->toBe(1);
});

it('does not mass assign platform_role', function (): void {
    $user = new User(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret', 'platform_role' => 'admin']);

    expect($user->platform_role)->toBeNull();
});
