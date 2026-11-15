<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Reminder;
use App\Modules\Credentials\Notifications\CredentialsDue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC')); // 10:00 in Kuala Lumpur
    $this->admin = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->admin, 'Acme');
    [$this->owner, $this->backup] = Tenancy::run($this->organisation, fn (): array => [
        Person::factory()->create(['email' => 'siti@acme.test']),
        Person::factory()->create(['email' => 'raj@acme.test']),
    ]);
});

function overdueBy(int $days): Credential
{
    return Tenancy::run(test()->organisation, fn () => Credential::factory()->overdueBy($days)->create([
        'application_id' => Application::factory()->create()->id, 'owner_id' => test()->owner->id, 'backup_owner_id' => test()->backup->id,
    ]));
}

function onTeamUntil(?Carbon $endsAt = null): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), test()->organisation, Module::Credentials, now()->subDays(30), $endsAt);
}

/**
 * @return list<string> the audiences reminded so far
 */
function audiences(): array
{
    return Tenancy::run(test()->organisation, fn (): array => Reminder::query()->whereNotNull('sent_at')->pluck('audience')
        ->map(fn ($audience): string => $audience->value)->sort()->values()->all());
}

it('reminds the backup owner from day 3 and the owners and admins from day 7, on Team', function (int $overdueDays, array $expected): void {
    onTeamUntil();
    overdueBy($overdueDays);

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    expect(audiences())->toBe($expected);
})->with([
    'day 1' => [1, ['owner']],
    'day 3' => [3, ['backup_owner', 'owner']],
    'day 7' => [7, ['admin', 'backup_owner', 'owner']],
    'a weekly repeat' => [21, ['admin', 'backup_owner', 'owner']],
]);

it('never escalates on Free', function (): void {
    overdueBy(7);

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    expect(audiences())->toBe(['owner']);
});

it('stops escalating after a downgrade', function (): void {
    onTeamUntil(now()->addMinute());
    overdueBy(6);
    $this->artisan('credentials:send-reminders')->assertSuccessful();
    expect(audiences())->toBe(['backup_owner', 'owner']);

    $this->travel(1)->days();
    app(Entitlements::class)->forget($this->organisation);
    $this->artisan('credentials:send-reminders')->assertSuccessful();

    expect(audiences())->toBe(['backup_owner', 'owner', 'owner']);
});

it('reminds an admin who is also the owner only once', function (): void {
    onTeamUntil();
    Tenancy::run($this->organisation, fn () => $this->owner->update(['user_id' => $this->admin->id]));
    overdueBy(7);

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->admin, CredentialsDue::class, 1);
});

it('doesn\'t remind someone who has left the organisation', function (): void {
    onTeamUntil();
    $leaver = memberWithRole($this->organisation, SystemRole::Admin);
    Tenancy::run($this->organisation, fn () => $this->organisation->users()->detach($leaver->id));
    overdueBy(7);

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    Notification::assertNotSentTo($leaver, CredentialsDue::class);
    Notification::assertSentTo($this->admin, CredentialsDue::class);
});
