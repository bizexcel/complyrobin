<?php

namespace App\Core\Access\Enums;

/**
 * The roles every organisation starts with. Organisations can add their own roles later.
 */
enum SystemRole: string
{
    /** May do everything in the organisation (granted by a gate check, not by listing abilities). */
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Editor = 'Editor';
    case Approver = 'Approver';
    case Viewer = 'Viewer';

    /**
     * @return list<Ability>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Owner => [],
            self::Admin => [Ability::UpdateOrganisation, Ability::ManageMembers],
            self::Editor, self::Approver, self::Viewer => [],
        };
    }
}
