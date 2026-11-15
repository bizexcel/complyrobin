<?php

namespace App\Modules\Credentials\Enums;

enum ReminderKind: string
{
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
}
