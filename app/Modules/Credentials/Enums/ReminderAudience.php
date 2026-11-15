<?php

namespace App\Modules\Credentials\Enums;

/**
 * Why a recipient got a reminder.
 */
enum ReminderAudience: string
{
    case Owner = 'owner';
    case BackupOwner = 'backup_owner';

    /** The organisation's Owners and Admins: when overdue reminders escalate, or when nobody else can be reached. */
    case Admin = 'admin';

    /** A team chat channel (Team). */
    case Channel = 'channel';
}
