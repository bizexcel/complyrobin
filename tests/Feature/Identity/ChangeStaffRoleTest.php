<?php

use App\Core\Identity\Actions\ChangeStaffRole;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\StaffRoleChange;
use App\Core\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
});

/**
 * The first validation message the action gave, or null if it succeeded.
 */
function staffChangeRefusal(?User $actor, string $email, ?PlatformRole $role): ?string
{
    try {
        app(ChangeStaffRole::class)->handle($actor, $email, $role);

        return null;
    } catch (ValidationException $exception) {
        return collect($exception->errors())->flatten()->first();
    }
}

it('makes someone staff and records who did it, from what role to what', function (): void {
    $aminah = User::factory()->create();

    app(ChangeStaffRole::class)->handle($this->admin, $aminah->email, PlatformRole::Support);
    app(ChangeStaffRole::class)->handle($this->admin, $aminah->email, PlatformRole::Admin);
    app(ChangeStaffRole::class)->handle($this->admin, $aminah->email, null);

    expect($aminah->refresh()->platform_role)->toBeNull()
        ->and(staffRoleChanges()->map(fn (StaffRoleChange $change): array => [
            $change->user_id, $change->changed_by_id, $change->from_role, $change->to_role,
        ])->all())->toBe([
            [$aminah->id, $this->admin->id, null, PlatformRole::Support],
            [$aminah->id, $this->admin->id, PlatformRole::Support, PlatformRole::Admin],
            [$aminah->id, $this->admin->id, PlatformRole::Admin, null],
        ]);
});

it('records nothing when the role doesn\'t change', function (): void {
    $aminah = User::factory()->create(['platform_role' => PlatformRole::Support]);

    app(ChangeStaffRole::class)->handle($this->admin, $aminah->email, PlatformRole::Support);
    app(ChangeStaffRole::class)->handle($this->admin, User::factory()->create()->email, null);

    expect(staffRoleChanges())->toBeEmpty();
});

it('refuses to make someone staff who hasn\'t verified their email or set up multi-factor authentication', function (): void {
    $unverified = User::factory()->unverified()->create();
    $withoutMfa = User::factory()->withoutMultiFactorAuthentication()->create();

    expect([
        staffChangeRefusal($this->admin, $unverified->email, PlatformRole::Support),
        staffChangeRefusal($this->admin, $withoutMfa->email, PlatformRole::Support),
        staffChangeRefusal($this->admin, 'nobody@example.com', PlatformRole::Support),
    ])->toBe([
        'They must verify their email address first.',
        'They must sign in once and set up multi-factor authentication first.',
        'No ComplyRobin account uses that email. Ask them to sign up first.',
    ])->and($unverified->refresh()->platform_role)->toBeNull()
        ->and($withoutMfa->refresh()->platform_role)->toBeNull();
});

it('matches the email exactly, as signing in does, so it never picks a different account', function (): void {
    $aminah = User::factory()->create(['email' => 'aminah@example.com']);

    expect(staffChangeRefusal($this->admin, 'Aminah@Example.com', PlatformRole::Support))->toBe('No ComplyRobin account uses that email. Ask them to sign up first.')
        ->and($aminah->refresh()->platform_role)->toBeNull();
});

it('lets only an Admin manage staff', function (?PlatformRole $actorRole): void {
    $actor = User::factory()->create(['platform_role' => $actorRole]);

    app(ChangeStaffRole::class)->handle($actor, User::factory()->create()->email, PlatformRole::Admin);
})->with(['Support' => PlatformRole::Support, 'a customer' => null])->throws(AuthorizationException::class);

it('refuses an Admin who was removed a moment ago, though their page still thinks they are one', function (): void {
    $other = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $staleAdmin = User::query()->findOrFail($this->admin->id);
    app(ChangeStaffRole::class)->handle($other, $this->admin->email, null);

    app(ChangeStaffRole::class)->handle($staleAdmin, User::factory()->create()->email, PlatformRole::Admin);
})->throws(AuthorizationException::class);

it('stops an Admin changing their own role, so nobody locks themself out by mistake', function (): void {
    expect(staffChangeRefusal($this->admin, $this->admin->email, null))->toBe('You can\'t change your own staff role. Ask another Admin.')
        ->and($this->admin->refresh()->platform_role)->toBe(PlatformRole::Admin);
});

it('always keeps at least one Admin', function (?PlatformRole $role): void {
    expect(staffChangeRefusal(null, $this->admin->email, $role))->toBe('ComplyRobin must keep at least one Admin. Make someone else an Admin first.')
        ->and($this->admin->refresh()->platform_role)->toBe(PlatformRole::Admin);
})->with(['removed' => null, 'made Support' => PlatformRole::Support]);

it('lets the last Admin go once there is another', function (): void {
    $other = User::factory()->create(['platform_role' => PlatformRole::Admin]);

    expect(staffChangeRefusal(null, $this->admin->email, null))->toBeNull()
        ->and(staffChangeRefusal(null, $other->email, null))->toBe('ComplyRobin must keep at least one Admin. Make someone else an Admin first.');
});

it('acts with no signed-in Admin only from the server console, which sets up the first Admin', function (): void {
    $aminah = User::factory()->create();
    (fn (): false => $this->isRunningInConsole = false)->call(app());

    try {
        app(ChangeStaffRole::class)->handle(null, $aminah->email, PlatformRole::Admin);
    } finally {
        (fn (): true => $this->isRunningInConsole = true)->call(app());
    }
})->throws(AuthorizationException::class);

it('keeps the record of staff changes append-only, and out of the customer app\'s reach', function (string $connection, string $statement): void {
    app(ChangeStaffRole::class)->handle($this->admin, User::factory()->create()->email, PlatformRole::Support);

    DB::connection($connection)->statement($statement);
})->with([
    'the platform changes an entry' => ['pgsql_platform', 'update staff_role_changes set to_role = \'admin\''],
    'the platform deletes an entry' => ['pgsql_platform', 'delete from staff_role_changes'],
    'the app reads the entries' => ['pgsql', 'select count(*) from staff_role_changes'],
    'the app adds an entry' => ['pgsql', 'insert into staff_role_changes (id, created_at) values (gen_random_uuid(), now())'],
])->throws(QueryException::class, 'permission denied');
