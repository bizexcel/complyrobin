<?php

namespace App\Modules\Credentials\Commands;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\ClaimDueReminders;
use App\Modules\Credentials\Jobs\DeliverReminders;
use App\Modules\Credentials\Support\ReminderPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs hourly. Finds the organisations with credentials coming due, and for each one inside its sending hours
 * (organisation time) claims the reminders due now and queues one digest per recipient. One organisation failing
 * never stops the others.
 */
#[Signature('credentials:send-reminders')]
#[Description('Send credential rotation reminders that are due')]
class SendReminders extends Command
{
    /** A credential this far ahead can't have a reminder due (the longest "before" step is 90 days). */
    private const int HORIZON_DAYS = 92;

    public function handle(ClaimDueReminders $claimDueReminders): int
    {
        $now = CarbonImmutable::now();

        $organisationIds = Tenancy::bypass('find organisations with credentials coming due', fn () => DB::table('credentials')
            ->whereNull('revoked_at')->whereNull('deleted_at')
            ->where('next_rotation_at', '<=', $now->addDays(self::HORIZON_DAYS))
            ->distinct()->pluck('organisation_id'));

        $queued = 0;

        foreach (Organisation::query()->whereKey($organisationIds)->get() as $organisation) {
            if (! $this->isSendingHour($organisation, $now)) {
                continue;
            }

            rescue(function () use ($organisation, $claimDueReminders, $now, &$queued): void {
                Tenancy::run($organisation, function () use ($claimDueReminders, $now, &$queued): void {
                    foreach ($claimDueReminders->handle($now) as $reminderIds) {
                        DeliverReminders::dispatch($reminderIds);
                        $queued++;
                    }
                });
            });
        }

        $this->components->info("Queued {$queued} reminder digests.");

        return self::SUCCESS;
    }

    private function isSendingHour(Organisation $organisation, CarbonImmutable $now): bool
    {
        $policy = ReminderPolicy::for($organisation);
        $hour = $now->setTimezone($organisation->timezone)->hour;

        return $hour >= $policy->sendHour && $hour < $policy->sendHour + $policy->sendWindowHours;
    }
}
