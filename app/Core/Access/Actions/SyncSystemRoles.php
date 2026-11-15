<?php

namespace App\Core\Access\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Models\Role;
use Illuminate\Support\Facades\DB;

final class SyncSystemRoles
{
    /**
     * Make sure the current organisation has every system role with every ability it should grant. Only adds: an
     * ability an organisation gave a role on purpose is never taken away. Runs for new organisations, and for every
     * organisation on deploy (`access:sync-system-roles`) so new abilities reach them.
     */
    public function handle(): void
    {
        DB::transaction(function (): void {
            foreach (SystemRole::cases() as $systemRole) {
                $role = Role::query()->firstOrCreate(['name' => $systemRole->value, 'guard_name' => 'web']);
                $missing = array_values(array_filter(
                    array_map(fn (Ability $ability): string => $ability->value, $systemRole->abilities()),
                    fn (string $ability): bool => ! $role->hasPermissionTo($ability),
                ));

                if ($missing !== []) {
                    $role->givePermissionTo($missing);
                }
            }
        });
    }
}
