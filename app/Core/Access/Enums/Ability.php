<?php

namespace App\Core\Access\Enums;

/**
 * Permissions a role can grant inside an organisation. Stored as spatie permissions by `PermissionSeeder`.
 */
enum Ability: string
{
    case UpdateOrganisation = 'organisation.update';
    case ManageMembers = 'members.manage';
}
