<?php

namespace App\Core\Access\Commands;

use App\Core\Access\Actions\SyncSystemRoles;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gives every organisation's system roles any abilities added since it was created. Run on deploy, after
 * PermissionSeeder.
 */
#[Signature('access:sync-system-roles')]
#[Description('Add new abilities to every organisation\'s system roles')]
class SyncSystemRolesCommand extends Command
{
    public function handle(SyncSystemRoles $syncSystemRoles): int
    {
        $count = 0;

        Tenancy::each(function (Organisation $organisation) use ($syncSystemRoles, &$count): void {
            $syncSystemRoles->handle();
            $count++;
        });

        $this->components->info("Synced system roles for {$count} organisations.");

        return self::SUCCESS;
    }
}
