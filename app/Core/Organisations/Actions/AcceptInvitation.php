<?php

namespace App\Core\Organisations\Actions;

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Limit;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Exceptions\InvalidInvitation;
use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AcceptInvitation
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * Join the organisation the invitation is for, with its role. The signed-in user's verified email must be the invited one.
     * The token is looked up inside the organisation named in the link, so a token can't be used anywhere else.
     * Throws InvalidInvitation for an unknown, expired or used link, and AuthorizationException for the wrong user.
     */
    public function handle(User $user, Organisation $organisation, string $token): Organisation
    {
        return Tenancy::run($organisation, fn (): Organisation => DB::transaction(function () use ($user, $organisation, $token): Organisation {
            $invitation = Invitation::query()
                ->where('token', hash('sha256', $token))
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first() ?? throw InvalidInvitation::make();

            // A verified address proves the user owns the invited email; otherwise anyone could sign up with it.
            if (strcasecmp($invitation->email, $user->email) !== 0 || ! $user->hasVerifiedEmail()) {
                throw new AuthorizationException('This invitation was sent to a different email address.');
            }

            if (! $user->canAccessTenant($organisation)) {
                $limit = $this->entitlements->limit(Limit::Members);

                if ($limit !== null && $organisation->users()->count() >= $limit) {
                    throw new AuthorizationException('This organisation has reached its plan\'s member limit.');
                }

                $organisation->users()->attach($user);
            }

            $user->assignRole($invitation->role);
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $organisation;
        }));
    }
}
