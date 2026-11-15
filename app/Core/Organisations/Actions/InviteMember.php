<?php

namespace App\Core\Organisations\Actions;

use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Access\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Notifications\InvitationNotification;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InviteMember
{
    public const int DAYS_VALID = 7;

    /**
     * Invite someone to the current organisation with a role, replacing any open invitation for the same email.
     * Only an Owner may invite another Owner.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $email, string $role): Invitation
    {
        Gate::forUser($actor)->authorize(Ability::ManageMembers->value);

        $organisation = Organisation::query()->findOrFail(Tenancy::id());
        $email = Str::lower(trim($email));

        Validator::make(['email' => $email, 'role' => $role], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', 'string'],
        ])->after(function ($validator) use ($actor, $organisation, $email, $role): void {
            if (! Role::query()->where('name', $role)->exists()) {
                $validator->errors()->add('role', 'Choose one of the organisation\'s roles.');
            } elseif ($role === SystemRole::Owner->value && ! $actor->hasRole(SystemRole::Owner->value)) {
                $validator->errors()->add('role', 'Only an owner can invite another owner.');
            }

            if ($organisation->users()->whereRaw('lower(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', 'This person is already a member.');
            }
        })->validate();

        $token = Str::random(48);

        $invitation = DB::transaction(fn (): Invitation => Invitation::query()->updateOrCreate(
            ['email' => $email, 'accepted_at' => null],
            [
                'role' => $role,
                'token' => hash('sha256', $token),
                'invited_by_id' => $actor->getKey(),
                'expires_at' => now()->addDays(self::DAYS_VALID),
            ],
        ));

        Notification::route('mail', $email)->notify(new InvitationNotification(
            $organisation,
            route('invitations.accept', ['organisation' => $organisation->slug, 'token' => $token]),
        ));

        return $invitation;
    }
}
