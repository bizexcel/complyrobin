<?php

namespace App\Core\Organisations;

use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Models\Organisation;

/**
 * Seats on the plan's member limit: members plus open invitations, as invitations hold a seat until they expire.
 */
final class Seats
{
    /**
     * @param  string|null  $exceptInvitationTo  an email whose open invitation is about to be replaced, so isn't counted
     */
    public static function taken(Organisation $organisation, ?string $exceptInvitationTo = null): int
    {
        $openInvitations = Invitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->when($exceptInvitationTo !== null, fn ($query) => $query->where('email', '!=', $exceptInvitationTo))
            ->count();

        return $organisation->users()->count() + $openInvitations;
    }
}
