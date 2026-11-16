<?php

namespace App\Core\Identity\Actions;

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\StaffRoleChange;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ChangeStaffRole
{
    /**
     * Make someone ComplyRobin staff, change their staff role, or remove them from staff (role null). The only way a
     * staff role changes.
     *
     * Only an Admin may, and never on their own account. With no actor it runs from the server console, which is how the
     * first Admin is made. Someone becomes staff only once their email is verified and they have multi-factor
     * authentication, and ComplyRobin always keeps at least one Admin. Each change is recorded in staff_role_changes.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(?User $actor, string $email, ?PlatformRole $role): User
    {
        if (! $actor instanceof User && ! app()->runningInConsole()) {
            throw new AuthorizationException('Only an Admin can manage staff.');
        }

        return Tenancy::bypass('staff: change a staff role', fn (): User => DB::transaction(function () use ($actor, $email, $role): User {
            // Every Admin row is locked first, always in the same order, so two Admins removing each other at the same
            // moment can't leave none: the second waits, then sees the first change.
            $admins = User::query()->where('platform_role', PlatformRole::Admin)->orderBy('id')->lockForUpdate()->pluck('id');
            // Exactly as signing in matches it, so this can never pick a different account.
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            // Checked against the locked rows, not the signed-in copy, so an Admin demoted a moment ago can't act.
            if ($actor instanceof User && ! $admins->contains($actor->getKey())) {
                throw new AuthorizationException('Only an Admin can manage staff.');
            }

            if (! $user instanceof User) {
                throw ValidationException::withMessages(['email' => 'No ComplyRobin account uses that email. Ask them to sign up first.']);
            }

            if ($actor instanceof User && $user->is($actor)) {
                throw ValidationException::withMessages(['email' => 'You can\'t change your own staff role. Ask another Admin.']);
            }

            if ($user->platform_role === $role) {
                return $user;
            }

            if ($role instanceof PlatformRole && $user->email_verified_at === null) {
                throw ValidationException::withMessages(['email' => 'They must verify their email address first.']);
            }

            if ($role instanceof PlatformRole && $user->getAppAuthenticationSecret() === null && ! $user->hasEmailAuthentication()) {
                throw ValidationException::withMessages(['email' => 'They must sign in once and set up multi-factor authentication first.']);
            }

            if ($user->platform_role === PlatformRole::Admin && $admins->count() === 1) {
                throw ValidationException::withMessages(['role' => 'ComplyRobin must keep at least one Admin. Make someone else an Admin first.']);
            }

            $from = $user->platform_role;
            $user->forceFill(['platform_role' => $role])->save();

            StaffRoleChange::query()->create([
                'user_id' => $user->getKey(),
                'changed_by_id' => $actor?->getKey(),
                'from_role' => $from,
                'to_role' => $role,
            ]);

            return $user;
        }));
    }
}
