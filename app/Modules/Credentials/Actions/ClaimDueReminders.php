<?php

namespace App\Modules\Credentials\Actions;

use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Reminder;
use App\Modules\Credentials\Support\ReminderPlanner;
use App\Modules\Credentials\Support\ReminderPolicy;
use App\Modules\Credentials\Support\ReminderRecipients;
use App\Modules\Credentials\Support\ReminderStep;
use App\Modules\Credentials\Support\RotationSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ClaimDueReminders
{
    /** Claims not delivered after this long are handed out again (the delivery job may have died)... */
    private const int RETRY_AFTER_MINUTES = 30;

    /** ...for a day, then given up as failed. */
    private const int GIVE_UP_AFTER_HOURS = 24;

    public function __construct(private ReminderRecipients $recipients) {}

    /**
     * Claim the current organisation's reminders due now: the current step of each credential, for each recipient.
     * Claiming is an insert into the send log that does nothing if the row exists, so each reminder is claimed once
     * however often this runs. Returns the claimed reminder ids grouped by recipient, one digest each.
     *
     * @return array<string, list<string>>
     */
    public function handle(CarbonInterface $now): array
    {
        $organisation = Organisation::query()->findOrFail(Tenancy::id());
        $policy = ReminderPolicy::for($organisation);
        $furthestAhead = ($policy->before === [] ? 0 : max($policy->before)) + 1;
        $furthestBehind = ($policy->overdue === [] ? 0 : max($policy->overdue)) + 7 * $policy->repeatWeeklyForWeeks + 1;

        $credentials = Credential::query()->active()
            ->with(['owner.user', 'backupOwner.user'])
            ->whereBetween('next_rotation_at', [$now->copy()->subDays($furthestBehind), $now->copy()->addDays($furthestAhead)])
            ->get();

        $claims = [];

        foreach ($credentials as $credential) {
            $step = ReminderPlanner::currentStep(RotationSchedule::daysUntilDue($credential->next_rotation_at, $now, $organisation->timezone), $policy);

            if (! $step instanceof ReminderStep) {
                continue;
            }

            foreach ($this->recipients->for($credential, $step) as $recipient) {
                $id = DB::scalar(<<<'SQL'
                    insert into reminders (id, organisation_id, credential_id, person_id, user_id, kind, audience, offset_days, due_at, created_at)
                    values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    on conflict do nothing
                    returning id
                    SQL, [
                    (string) Str::uuid7(), $organisation->getKey(), $credential->getKey(), $recipient->person?->getKey(),
                    $recipient->user?->getKey(), $step->kind->value, $recipient->audience->value, $step->offsetDays,
                    $credential->next_rotation_at, $now,
                ]);

                if (is_string($id)) {
                    $claims[$recipient->key()][] = $id;
                }
            }
        }

        return $this->merge($claims, $this->unsentClaims($now));
    }

    /**
     * Claims whose delivery never finished: retried for a day, then marked failed.
     *
     * @return array<string, list<string>>
     */
    private function unsentClaims(CarbonInterface $now): array
    {
        Reminder::query()->whereNull('sent_at')->whereNull('failed_at')
            ->where('created_at', '<', $now->copy()->subHours(self::GIVE_UP_AFTER_HOURS))
            ->update(['failed_at' => $now]);

        return Reminder::query()->whereNull('sent_at')->whereNull('failed_at')
            ->where('created_at', '<', $now->copy()->subMinutes(self::RETRY_AFTER_MINUTES))
            ->get(['id', 'person_id', 'user_id'])
            ->groupBy(fn (Reminder $reminder): string => $reminder->person_id !== null ? "person:{$reminder->person_id}" : "user:{$reminder->user_id}")
            ->map(fn ($reminders): array => $reminders->pluck('id')->values()->all())
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $first
     * @param  array<string, list<string>>  $second
     * @return array<string, list<string>>
     */
    private function merge(array $first, array $second): array
    {
        foreach ($second as $key => $ids) {
            $first[$key] = array_values(array_unique([...($first[$key] ?? []), ...$ids]));
        }

        return $first;
    }
}
