<?php

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\AcceptInvitation;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

function inviteTo(Organisation $organisation, User $actor, string $email): void
{
    Tenancy::run($organisation, fn () => app(InviteMember::class)->handle($actor, $email, 'Editor'));
}

it('lets a Free organisation reach 3 members, counting open invitations', function (): void {
    inviteTo($this->organisation, $this->owner, 'bob@example.com');
    inviteTo($this->organisation, $this->owner, 'cara@example.com');

    try {
        inviteTo($this->organisation, $this->owner, 'dan@example.com');
        $this->fail('Invited a fourth member on Free.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['email'][0])->toContain('3 members');
    }
});

it('doesn\'t count an invitation twice when it is sent again', function (): void {
    inviteTo($this->organisation, $this->owner, 'bob@example.com');
    inviteTo($this->organisation, $this->owner, 'bob@example.com');

    inviteTo($this->organisation, $this->owner, 'cara@example.com');

    expect(Tenancy::run($this->organisation, fn () => Invitation::query()->orderBy('email')->pluck('email')->all()))
        ->toBe(['bob@example.com', 'cara@example.com']);
});

it('allows more members on Team', function (): void {
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    app(GrantModule::class)->handle($staff, $this->organisation, Module::Credentials, now()->subDay());

    foreach (range(1, 9) as $number) {
        inviteTo($this->organisation, $this->owner, "member{$number}@example.com");
    }

    expect(fn () => inviteTo($this->organisation, $this->owner, 'member10@example.com'))->toThrow(ValidationException::class);
});

it('refuses to accept an invitation once the organisation is full', function (): void {
    $invitee = User::factory()->create(['email' => 'bob@example.com']);
    inviteTo($this->organisation, $this->owner, 'bob@example.com');
    Tenancy::run($this->organisation, fn () => $this->organisation->users()->attach(User::factory()->count(2)->create()));

    app(AcceptInvitation::class)->handle($invitee, $this->organisation, basename(invitationLink('bob@example.com')));
})->throws(AuthorizationException::class, 'member limit');

it('keeps everyone after a downgrade, but invites no one new', function (): void {
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    app(GrantModule::class)->handle($staff, $this->organisation, Module::Credentials, now()->subDays(30), now()->addMinute());
    Tenancy::run($this->organisation, fn () => $this->organisation->users()->attach(User::factory()->count(4)->create()));

    $this->travel(2)->minutes();
    app(Entitlements::class)->forget($this->organisation);

    expect(Tenancy::run($this->organisation, fn (): int => $this->organisation->users()->count()))->toBe(5)
        ->and(fn () => inviteTo($this->organisation, $this->owner, 'late@example.com'))->toThrow(ValidationException::class);
});
