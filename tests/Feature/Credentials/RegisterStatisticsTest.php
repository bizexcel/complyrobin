<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Widgets\CredentialsDueSoon;
use App\Filament\App\Widgets\CredentialsOverview;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Support\RegisterStatistics;
use App\Modules\Credentials\Support\ReminderRecipients;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    // 20:00 UTC on 1 June is 04:00 on 2 June in Kuala Lumpur, the organisation's time zone.
    $this->travelTo(Carbon::parse('2026-06-01 20:00', 'UTC'));
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function registerCredentialDueAt(string $dueAtUtc, array $attributes = []): Credential
{
    return Tenancy::run(test()->organisation, fn (): Credential => Credential::factory()->create([
        'application_id' => Application::factory()->create()->id,
        'rotation_days' => 30,
        'last_rotated_at' => Carbon::parse($dueAtUtc, 'UTC')->subDays(30),
        'expires_at' => null,
        ...$attributes,
    ])->refresh());
}

function registerStatistics(): RegisterStatistics
{
    return Tenancy::run(test()->organisation, fn (): RegisterStatistics => RegisterStatistics::for(test()->organisation, now()));
}

it('counts overdue and due-soon credentials on the organisation\'s own days', function (): void {
    registerCredentialDueAt('2026-06-01 15:00');                      // 23:00 on 1 June in KL: overdue there
    registerCredentialDueAt('2026-06-01 17:00');                      // 01:00 on 2 June in KL: due today, not overdue
    registerCredentialDueAt('2026-06-30 12:00');                      // within 30 days
    registerCredentialDueAt('2026-07-20 12:00');                      // later
    registerCredentialDueAt('2026-05-01 12:00', ['revoked_at' => now()]);

    expect(registerStatistics())->overdue->toBe(1)->dueSoon->toBe(2);
});

it('works out the share of the last 90 days\' rotations done on time, by the organisation\'s days', function (): void {
    $credential = registerCredentialDueAt('2026-07-20 12:00');
    $rotation = fn (string $dueAt, string $rotatedAt) => Tenancy::run($this->organisation, fn () => CredentialRotation::query()->create([
        'credential_id' => $credential->id, 'rotated_by_id' => $this->owner->id, 'due_at' => $dueAt, 'rotated_at' => $rotatedAt,
    ]));

    expect(registerStatistics()->onTimePercent)->toBeNull();

    $rotation('2026-05-20 12:00', '2026-05-19 12:00');        // a day early
    $rotation('2026-05-01 12:00', '2026-05-03 12:00');        // two days late
    $rotation('2026-05-10 23:00', '2026-05-11 01:00');        // the next day in UTC, the same day (11 May) in KL
    $rotation('2026-02-01 12:00', '2026-02-20 12:00');        // late, but more than 90 days ago

    expect(registerStatistics())->onTimePercent->toBe(66)->rotations->toBe(3);
});

it('counts the credentials whose reminders have no owner to go to, as reminders decide it', function (): void {
    $leaver = User::factory()->create();
    $member = memberWithRole($this->organisation, SystemRole::Viewer);
    [$deleted, $noEmailNoLogin, $noEmailLeaver, $withEmail, $noEmailMember] = Tenancy::run($this->organisation, fn (): array => [
        tap(Person::factory()->create())->delete(),
        Person::factory()->create(['email' => null, 'user_id' => null]),
        Person::factory()->create(['email' => null, 'user_id' => $leaver->id]),
        Person::factory()->create(['email' => 'siti@acme.test']),
        Person::factory()->create(['email' => null, 'user_id' => $member->id]),
    ]);
    $due = '2026-06-20 12:00';
    $credentials = [
        registerCredentialDueAt($due, ['owner_id' => null]),
        registerCredentialDueAt($due, ['owner_id' => $deleted->id]),
        registerCredentialDueAt($due, ['owner_id' => $noEmailNoLogin->id]),
        registerCredentialDueAt($due, ['owner_id' => $noEmailLeaver->id]),
        registerCredentialDueAt($due, ['owner_id' => $withEmail->id]),
        registerCredentialDueAt($due, ['owner_id' => $noEmailMember->id]),
    ];
    registerCredentialDueAt($due, ['owner_id' => null, 'revoked_at' => now()]);

    expect(registerStatistics()->withoutReachableOwner)->toBe(4);

    Tenancy::run($this->organisation, function () use ($credentials): void {
        $counted = RegisterStatistics::withoutReachableOwner($this->organisation)->pluck('id')->all();

        foreach ($credentials as $credential) {
            $owner = Credential::query()->with('owner')->findOrFail($credential->id)->owner;
            $reachable = $owner instanceof Person && ReminderRecipients::isReachable($owner);

            expect(in_array($credential->id, $counted, true))->toBe(! $reachable, "{$credential->reference}");
        }
    });
});

it('never counts another organisation\'s credentials', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($globex, fn () => Credential::factory()->overdueBy(3)->count(2)->create(['application_id' => Application::factory()->create()->id, 'owner_id' => null]));

    expect(registerStatistics())->overdue->toBe(0)->dueSoon->toBe(0)->withoutReachableOwner->toBe(0);
});

it('shows the overview and what is up for rotation to every member', function (): void {
    $overdue = registerCredentialDueAt('2026-05-25 12:00', ['name' => 'GitHub deploy key']);
    $dueSoon = registerCredentialDueAt('2026-06-15 12:00', ['name' => 'Stripe live key']);
    $later = registerCredentialDueAt('2026-08-15 12:00', ['name' => 'Postmark server token']);
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(CredentialsOverview::class)->assertOk()->assertSee('Overdue')->assertSee('Due in 30 days')->assertSee('Rotated on time');
    Livewire::test(CredentialsDueSoon::class)
        ->assertCanSeeTableRecords([$overdue, $dueSoon], inOrder: true)
        ->assertCanNotSeeTableRecords([$later]);
});
