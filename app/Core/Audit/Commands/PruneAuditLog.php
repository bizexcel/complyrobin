<?php

namespace App\Core\Audit\Commands;

use App\Core\Audit\Models\Activity;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes audit entries older than the retention period (activitylog.clean_after_days, two years). The app's own
 * role can't delete audit entries, so this runs on the platform connection.
 */
#[Signature('audit:prune')]
#[Description('Delete audit log entries older than the retention period')]
class PruneAuditLog extends Command
{
    public function handle(): int
    {
        $days = (int) config('activitylog.clean_after_days');

        $deleted = Tenancy::bypass('prune audit log past retention', fn (): int => Activity::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete());

        $this->components->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
