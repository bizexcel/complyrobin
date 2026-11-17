<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Actions\RevokeInvitation;
use App\Core\Organisations\Models\Invitation;
use App\Core\Organisations\Notifications\InvitationNotification;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\Members;
use App\Filament\App\Pages\Widgets\OpenInvitations;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create(['name' => 'Aisyah']);
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onProPlan($this->organisation);
});

function membersPageInvite(string $email, string $role = 'Editor'): Invitation
{
    return Tenancy::run(test()->organisation, fn (): Invitation => app(InviteMember::class)->handle(test()->owner, $email, $role));
}

it('lists the organisation\'s members with their roles, and no one else', function (): void {
    $admin = memberWithRole($this->organisation, SystemRole::Admin);
    $outsider = User::factory()->create();
    app(CreateOrganisation::class)->handle($outsider, 'Globex');
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Members::class)
        ->assertCanSeeTableRecords([$this->owner, $admin])
        ->assertCanNotSeeTableRecords([$outsider])
        ->assertSee('Owner')->assertSee('Admin');
});

it('lets an Admin invite someone, counting seats against the plan', function (): void {
    $admin = memberWithRole($this->organisation, SystemRole::Admin);
    openOrganisationAs($admin, $this->organisation);

    Livewire::test(Members::class)
        ->assertSee('2 of 5 seats on Pro, counting open invitations')
        ->callAction('invite', ['email' => 'Siti@Acme.test', 'role' => 'Editor'])
        ->assertNotified('Invitation sent to Siti@Acme.test.');

    Notification::assertSentOnDemand(InvitationNotification::class);
    expect(Invitation::query()->sole())->email->toBe('siti@acme.test')->role->toBe('Editor');
    Livewire::test(Members::class)->assertSee('3 of 5 seats on Pro, counting open invitations');
});

it('doesn\'t offer inviting to an Editor', function (): void {
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);

    Livewire::test(Members::class)->assertActionHidden('invite');
});

it('says so when the plan\'s member limit is reached', function (): void {
    memberWithRole($this->organisation, SystemRole::Editor);
    foreach (['ahmad', 'bala', 'chen'] as $name) {
        membersPageInvite("{$name}@acme.test");
    }
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(Members::class)
        ->callAction('invite', ['email' => 'mei@acme.test', 'role' => 'Viewer'])
        ->assertNotified('Your plan allows 5 members, counting open invitations. Upgrade to invite more.');

    expect(Invitation::query()->orderBy('email')->pluck('email')->all())->toBe(['ahmad@acme.test', 'bala@acme.test', 'chen@acme.test']);
});

it('revokes an open invitation, freeing its seat', function (): void {
    $invitation = membersPageInvite('ahmad@acme.test');
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);
    Livewire::test(OpenInvitations::class)
        ->assertCanSeeTableRecords([$invitation])
        ->assertActionHidden(TestAction::make('revoke')->table($invitation));

    openOrganisationAs($this->owner, $this->organisation);
    Livewire::test(OpenInvitations::class)->callAction(TestAction::make('revoke')->table($invitation));

    expect(Invitation::query()->count())->toBe(0);
    Livewire::test(Members::class)->assertSee('2 of 5 seats on Pro');
});

it('lets only members who manage members revoke, and only open invitations', function (): void {
    $invitation = membersPageInvite('ahmad@acme.test');
    $editor = memberWithRole($this->organisation, SystemRole::Editor);

    expect(fn () => Tenancy::run($this->organisation, fn () => app(RevokeInvitation::class)->handle($editor, $invitation)))
        ->toThrow(AuthorizationException::class);

    Tenancy::run($this->organisation, fn () => $invitation->forceFill(['accepted_at' => now()])->save());

    expect(fn () => Tenancy::run($this->organisation, fn () => app(RevokeInvitation::class)->handle($this->owner, $invitation)))
        ->toThrow(ValidationException::class, 'already been accepted');
});
