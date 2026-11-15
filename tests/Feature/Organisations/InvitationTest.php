<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Notifications\InvitationNotification;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

/**
 * A member of the organisation with the given role.
 */
function memberWithRole(Organisation $organisation, SystemRole $role): User
{
    $user = User::factory()->create();
    Tenancy::run($organisation, function () use ($organisation, $user, $role): void {
        $organisation->users()->attach($user);
        $user->assignRole($role->value);
    });

    return $user;
}

function invite(Organisation $organisation, User $actor, string $email, string $role = 'Editor'): Invitation
{
    return Tenancy::run($organisation, fn () => app(InviteMember::class)->handle($actor, $email, $role));
}

/**
 * The link emailed to the address.
 */
function invitationLink(string $email): string
{
    $link = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$link): bool {
        $link = $notification->url;

        return $notifiable->routes['mail'] === $email;
    });

    return $link;
}

it('emails a link and stores only a hash of its token', function (): void {
    $invitation = invite($this->organisation, $this->owner, 'Bob@Example.com');

    $token = basename(invitationLink('bob@example.com'));

    expect($invitation->email)->toBe('bob@example.com')
        ->and($invitation->token)->toBe(hash('sha256', $token))
        ->and(invitationLink('bob@example.com'))->toEndWith("/invitations/acme/{$token}");
});

it('replaces an open invitation when the same person is invited again', function (): void {
    invite($this->organisation, $this->owner, 'bob@example.com', 'Viewer');
    invite($this->organisation, $this->owner, 'bob@example.com', 'Editor');

    expect(Tenancy::run($this->organisation, fn () => Invitation::query()->pluck('role')->all()))->toBe(['Editor']);
});

it('lets only members who manage members send invitations', function (): void {
    $viewer = memberWithRole($this->organisation, SystemRole::Viewer);

    invite($this->organisation, $viewer, 'bob@example.com');
})->throws(AuthorizationException::class);

it('rejects an invitation that is invalid for the organisation', function (SystemRole $actorRole, string $email, string $role, string $field): void {
    $actor = $actorRole === SystemRole::Owner ? $this->owner : memberWithRole($this->organisation, $actorRole);
    $member = memberWithRole($this->organisation, SystemRole::Editor);

    try {
        invite($this->organisation, $actor, $email === 'member' ? $member->email : $email, $role);
        $this->fail('Accepted the invitation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'unknown role' => [SystemRole::Owner, 'bob@example.com', 'Superhero', 'role'],
    'owner invited by an admin' => [SystemRole::Admin, 'bob@example.com', 'Owner', 'role'],
    'existing member' => [SystemRole::Owner, 'member', 'Editor', 'email'],
    'not an email' => [SystemRole::Owner, 'bob', 'Editor', 'email'],
]);

it('adds the invited user with the role and opens the organisation', function (): void {
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    invite($this->organisation, $this->owner, 'bob@example.com', 'Approver');

    $this->actingAs($bob)->get(invitationLink('bob@example.com'))->assertRedirect('/acme');

    expect($bob->canAccessTenant($this->organisation))->toBeTrue()
        ->and(Tenancy::run($this->organisation, fn (): bool => $bob->hasRole('Approver')))->toBeTrue();
});

it('refuses the invitation for anyone but the verified invited address', function (array $attributes): void {
    $user = User::factory()->create($attributes);
    invite($this->organisation, $this->owner, 'bob@example.com');

    $this->actingAs($user)->get(invitationLink('bob@example.com'))->assertForbidden();

    expect($user->canAccessTenant($this->organisation))->toBeFalse();
})->with([
    'another address' => [['email' => 'mallory@example.com']],
    'the address, unverified' => [['email' => 'bob@example.com', 'email_verified_at' => null]],
]);

it('returns 404 for a link that is unknown, expired, used or for another organisation', function (Closure $breakLink): void {
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    invite($this->organisation, $this->owner, 'bob@example.com');

    $this->actingAs($bob)->get($breakLink(invitationLink('bob@example.com'), $this->organisation))->assertNotFound();
})->with([
    'unknown token' => fn (string $link): string => dirname($link).'/not-a-real-token',
    'expired' => function (string $link, Organisation $organisation): string {
        Tenancy::run($organisation, fn () => Invitation::query()->update(['expires_at' => now()->subMinute()]));

        return $link;
    },
    'already used' => function (string $link, Organisation $organisation): string {
        Tenancy::run($organisation, fn () => Invitation::query()->update(['accepted_at' => now()]));

        return $link;
    },
    'another organisation' => function (string $link): string {
        app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');

        return str_replace('/invitations/acme/', '/invitations/globex/', $link);
    },
]);

it('asks a guest to sign in first, then brings them back to the invitation', function (): void {
    invite($this->organisation, $this->owner, 'bob@example.com');
    $link = invitationLink('bob@example.com');

    $this->get($link)->assertRedirect('/login');

    expect(session('url.intended'))->toBe($link);
});
