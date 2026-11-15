<?php

namespace App\Modules\Credentials\Support;

use App\Modules\Credentials\Enums\ReminderKind;

/**
 * One step of the reminder schedule: e.g. upcoming, 7 days before; or overdue, 3 days after.
 */
final readonly class ReminderStep
{
    public function __construct(public ReminderKind $kind, public int $offsetDays) {}
}
