<?php

namespace App\Core\Access\Enums;

/**
 * Permissions a role can grant inside an organisation. Stored as spatie permissions by `PermissionSeeder`.
 */
enum Ability: string
{
    case UpdateOrganisation = 'organisation.update';
    case ManageMembers = 'members.manage';
    case ManagePeople = 'people.manage';
    case ManageTasks = 'tasks.manage';
    case ManageCredentials = 'credentials.manage';
    case RotateCredentials = 'credentials.rotate';
    case ManageChannels = 'channels.manage';
    case ExportAudit = 'audit.export';
    case ManageBilling = 'billing.manage';
}
