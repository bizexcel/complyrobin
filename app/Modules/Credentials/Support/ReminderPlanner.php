<?php

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Enums\ReminderKind;

/**
 * Which reminder step applies now: the latest one already reached. Sending only the current step means a missed run
 * (or a credential added close to its due date) catches up with one reminder, never a burst of old ones.
 */
final class ReminderPlanner
{
    public static function currentStep(int $daysUntilDue, ReminderPolicy $policy): ?ReminderStep
    {
        if ($daysUntilDue > 0) {
            $reached = array_filter($policy->before, fn (int $days): bool => $daysUntilDue <= $days);

            return $reached === [] ? null : new ReminderStep(ReminderKind::Upcoming, min($reached));
        }

        $overdueDays = -$daysUntilDue;
        $reached = array_filter($policy->overdue, fn (int $days): bool => $overdueDays >= $days);

        if ($reached === []) {
            // Due, but before the first overdue step: the last upcoming step still applies.
            return $policy->before === [] ? null : new ReminderStep(ReminderKind::Upcoming, min($policy->before));
        }

        // After the last overdue step: once a week, for a limited number of weeks.
        $last = max($policy->overdue);
        $weeks = min(intdiv($overdueDays - $last, 7), $policy->repeatWeeklyForWeeks);

        return new ReminderStep(ReminderKind::Overdue, $weeks > 0 ? $last + 7 * $weeks : max($reached));
    }
}
