<?php

namespace App\Modules\Credentials\Support;

use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Members;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\ReminderAudience;
use App\Modules\Credentials\Models\Credential;

/**
 * Who should hear about a credential at a reminder step. A reminder always lands somewhere: if the owner can't be
 * reached (no owner, deleted, or neither an email nor a membership), the organisation's Owners and Admins get it.
 */
final class ReminderRecipients
{
    /**
     * @return list<ReminderRecipient>
     */
    public function for(Credential $credential): array
    {
        $owner = $credential->owner;

        if ($owner instanceof Person && self::isReachable($owner)) {
            return [new ReminderRecipient(ReminderAudience::Owner, person: $owner)];
        }

        return self::admins();
    }

    /**
     * @return list<ReminderRecipient>
     */
    public static function admins(): array
    {
        return Members::withRoles([SystemRole::Owner, SystemRole::Admin])
            ->map(fn (User $user): ReminderRecipient => new ReminderRecipient(ReminderAudience::Admin, user: $user))
            ->values()
            ->all();
    }

    public static function isReachable(Person $person): bool
    {
        return filled($person->email) || self::memberOf($person) instanceof User;
    }

    /**
     * The person's own login, while they are still a member of this organisation.
     */
    public static function memberOf(Person $person): ?User
    {
        $user = $person->user;

        return $user instanceof User && $user->canAccessTenant(Organisation::query()->findOrFail(Tenancy::id())) ? $user : null;
    }
}
