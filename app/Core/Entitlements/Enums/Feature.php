<?php

namespace App\Core\Entitlements\Enums;

/**
 * Paid capabilities. What every plan has (email and in-app reminders, the calendar feed, the rotation log) isn't listed.
 */
enum Feature: string
{
    /** Reminders to Slack, Microsoft Teams and Telegram. */
    case ChatChannels = 'chat_channels';

    /** Reminders by WhatsApp, which costs per message. */
    case WhatsApp = 'whatsapp';

    /** Overdue reminders escalate to the backup owner and then to admins. */
    case Escalation = 'escalation';

    /** File evidence on rotations and the 90-day rotation evidence report. */
    case EvidenceReport = 'evidence_report';

    case AuditExport = 'audit_export';

    case SpreadsheetImport = 'spreadsheet_import';

    /** A rotation runner swaps keys on schedule and records the evidence. */
    case AutomaticRotation = 'automatic_rotation';
}
