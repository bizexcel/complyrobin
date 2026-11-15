<?php

namespace App\Modules\Credentials\Support;

use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Members;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Feature;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\ReminderAudience;
use App\Modules\Credentials\Enums\ReminderKind;
use App\Modules\Credentials\Models\Credential;

/**
 * Who should hear about a credential at a reminder step.
 *
 * - The owner, always. If the owner can't be reached (no owner, deleted, or neither an email nor a membership), the
 *   organisation's Owners and Admins instead, so a reminder always lands.
 * - On Team (escalation), while overdue: the backup owner too from day 3, and Owners and Admins from day 7. Nobody
 *   gets the same reminder twice: an admin who is also the owner hears once.
 */
final readonly class ReminderRecipients
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @return list<ReminderRecipient>
     */
    public function for(Credential $credential, ReminderStep $step): array
    {
        $owner = $credential->owner;
        $recipients = $owner instanceof Person && self::isReachable($owner)
            ? [new ReminderRecipient(ReminderAudience::Owner, person: $owner)]
            : self::admins();

        if ($step->kind !== ReminderKind::Overdue || ! $this->entitlements->allows(Feature::Escalation)) {
            return $recipients;
        }

        $backup = $credential->backupOwner;

        if ($step->offsetDays >= (int) config('credentials.reminders.escalate_to_backup_after_days')
            && $backup instanceof Person && self::isReachable($backup) && ! $this->covers($recipients, $backup)) {
            $recipients[] = new ReminderRecipient(ReminderAudience::BackupOwner, person: $backup);
        }

        if ($step->offsetDays >= (int) config('credentials.reminders.escalate_to_admins_after_days')) {
            foreach (self::admins() as $admin) {
                if (! $this->covers($recipients, $admin->user)) {
                    $recipients[] = $admin;
                }
            }
        }

        return $recipients;
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

    /**
     * Whether someone (a person, or a member) already hears through one of the recipients.
     *
     * @param  list<ReminderRecipient>  $recipients
     */
    private function covers(array $recipients, Person|User|null $someone): bool
    {
        if ($someone === null) {
            return true;
        }

        $userId = $someone instanceof User ? $someone->getKey() : $someone->user_id;

        return array_any($recipients, fn (ReminderRecipient $recipient): bool => ($someone instanceof Person && $recipient->person?->is($someone))
            || ($userId !== null && ($recipient->user?->getKey() === $userId || $recipient->person?->user_id === $userId)));
    }
}
