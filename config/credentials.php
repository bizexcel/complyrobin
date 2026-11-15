<?php

/*
 * The credentials register. Organisations can change the reminder schedule in their settings; these are the defaults.
 */

return [
    'reminders' => [
        // Days before the due date, and days after it while still overdue.
        'before' => [14, 7, 1],
        'overdue' => [0, 3, 7],

        // After the last overdue step, remind weekly until rotated or revoked, for at most this many weeks.
        'repeat_weekly_for_weeks' => 12,

        // Reminders go out from this hour, organisation time, for this many hours.
        'send_hour' => 9,
        'send_window_hours' => 9,

        // On Team: overdue reminders also go to the backup owner, then to owners and admins.
        'escalate_to_backup_after_days' => 3,
        'escalate_to_admins_after_days' => 7,
    ],

    // A credential is "due soon" this many days before its due date.
    'due_soon_days' => 30,

    // A hint is the first or last few characters only, never enough to be the secret.
    'hint_max_length' => 8,

    'evidence' => [
        'max_files' => 5,
        'max_kilobytes' => 10 * 1024,
        'mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'txt'],
    ],
];
