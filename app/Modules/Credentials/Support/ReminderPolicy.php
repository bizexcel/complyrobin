<?php

namespace App\Modules\Credentials\Support;

use App\Core\Organisations\Models\Organisation;

/**
 * When an organisation is reminded about its credentials: days before the due date, days after it while overdue, and
 * the hour (organisation time) reminders start going out. Saved in the organisation's settings over the defaults in
 * config/credentials.php.
 */
final readonly class ReminderPolicy
{
    /**
     * @param  list<int>  $before  days before the due date, largest first
     * @param  list<int>  $overdue  days after the due date, smallest first (0 is the due day)
     */
    public function __construct(
        public array $before,
        public array $overdue,
        public int $sendHour,
        public int $sendWindowHours,
        public int $repeatWeeklyForWeeks,
    ) {}

    public static function for(Organisation $organisation): self
    {
        $settings = $organisation->settings ?? [];
        $setting = fn (string $key): mixed => data_get($settings, "reminders.{$key}", config("credentials.reminders.{$key}"));

        $before = array_map(intval(...), (array) $setting('before'));
        $overdue = array_map(intval(...), (array) $setting('overdue'));
        rsort($before);
        sort($overdue);

        return new self(
            before: $before,
            overdue: $overdue,
            sendHour: (int) $setting('send_hour'),
            sendWindowHours: (int) config('credentials.reminders.send_window_hours'),
            repeatWeeklyForWeeks: (int) config('credentials.reminders.repeat_weekly_for_weeks'),
        );
    }
}
