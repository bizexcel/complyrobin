<?php

namespace App\Modules\Credentials\Support;

use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Notifications\AutomaticRotationFailed;

/**
 * Tells people a runner's rotation failed or went quiet: the credential's owner and backup owner, or the
 * organisation's Owners and Admins when neither can sign in.
 */
final class RotationFailures
{
    public static function notify(RotationRun $run): void
    {
        $credential = $run->credential;

        if (! $credential instanceof Credential) {
            return;
        }

        $organisation = Organisation::query()->findOrFail(Tenancy::id());
        $notification = new AutomaticRotationFailed(
            $credential->reference,
            $credential->name,
            (string) $run->failure,
            route('filament.app.resources.credentials.view', ['tenant' => $organisation->slug, 'record' => $credential]),
        );

        foreach (self::recipients($credential) as $user) {
            $user->notify($notification);
        }
    }

    /**
     * @return list<User>
     */
    private static function recipients(Credential $credential): array
    {
        $users = [];

        foreach ([$credential->owner, $credential->backupOwner] as $person) {
            $user = $person instanceof Person ? ReminderRecipients::memberOf($person) : null;

            if ($user instanceof User) {
                $users[$user->getKey()] = $user;
            }
        }

        $users = array_values($users);

        if ($users !== []) {
            return $users;
        }

        return array_values(array_filter(array_map(fn (ReminderRecipient $recipient): ?User => $recipient->user, ReminderRecipients::admins())));
    }
}
