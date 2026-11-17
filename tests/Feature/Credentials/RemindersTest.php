<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\ClaimDueReminders;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Actions\SetAutomaticRotation;
use App\Modules\Credentials\Jobs\DeliverReminders;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Reminder;
use App\Modules\Credentials\Notifications\CredentialsDue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    // 02:00 UTC is 10:00 in Kuala Lumpur, inside the default sending hours (09:00-18:00).
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    $this->admin = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->admin, 'Acme');
    $this->owner = Tenancy::run($this->organisation, fn () => Person::factory()->create(['email' => 'siti@acme.test']));
    $this->application = Tenancy::run($this->organisation, fn () => Application::factory()->create(['name' => 'Laravel Cloud']));
});

function credentialDueAt(string $dueAt, array $attributes = [], ?Organisation $organisation = null): Credential
{
    $organisation ??= test()->organisation;

    return Tenancy::run($organisation, function () use ($dueAt, $attributes, $organisation): Credential {
        $application = $organisation->is(test()->organisation) ? test()->application : Application::factory()->create();
        $due = Carbon::parse($dueAt, 'UTC');

        return Credential::factory()->create([
            'application_id' => $application->id, 'owner_id' => test()->owner->id, 'rotation_days' => 90,
            'last_rotated_at' => $due->copy()->subDays(90), ...$attributes,
        ])->refresh();
    });
}

function sendReminders(): void
{
    test()->artisan('credentials:send-reminders')->assertSuccessful();
}

/**
 * @return list<string> "kind offset" of every reminder sent so far
 */
function sentSteps(?Organisation $organisation = null): array
{
    return Tenancy::run($organisation ?? test()->organisation, fn (): array => Reminder::query()->whereNotNull('sent_at')->orderBy('created_at')->get()
        ->map(fn (Reminder $reminder): string => "{$reminder->kind->value} {$reminder->offset_days}")->all());
}

it('reminds 14, 7 and 1 days before, on the due day, then 3 and 7 days after, once each', function (): void {
    credentialDueAt('2026-06-30 03:00');

    foreach (range(0, 40) as $day) {
        $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC')->addDays($day));
        sendReminders();
    }

    expect(sentSteps())->toBe(['upcoming 14', 'upcoming 7', 'upcoming 1', 'overdue 0', 'overdue 3', 'overdue 7']);
    Notification::assertSentTimes(CredentialsDue::class, 6);
});

it('never sends the same reminder twice, however often it runs', function (): void {
    credentialDueAt('2026-06-08 03:00');

    sendReminders();
    sendReminders();
    $this->travel(1)->hours();
    sendReminders();

    expect(sentSteps())->toBe(['upcoming 7']);
    Notification::assertSentTimes(CredentialsDue::class, 1);
});

it('refuses a duplicate reminder in the database, even with an empty recipient column', function (): void {
    credentialDueAt('2026-06-08 03:00');
    sendReminders();
    $reminder = Tenancy::run($this->organisation, fn () => Reminder::query()->sole());

    DB::connection('pgsql_migrator')->table('reminders')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $reminder->organisation_id, 'credential_id' => $reminder->credential_id,
        'person_id' => $reminder->person_id, 'user_id' => null, 'kind' => $reminder->kind->value, 'audience' => $reminder->audience->value,
        'offset_days' => $reminder->offset_days, 'due_at' => $reminder->due_at,
    ]);
})->throws(QueryException::class, 'reminders_once_unique');

it('sends only during the organisation\'s sending hours, 09:00 to 18:00 its time', function (string $utc, bool $sends): void {
    credentialDueAt('2026-06-08 03:00');
    $this->travelTo(Carbon::parse($utc, 'UTC'));

    sendReminders();

    expect(sentSteps())->toBe($sends ? ['upcoming 7'] : []);
})->with([
    '08:30 in Kuala Lumpur' => ['2026-06-01 00:30', false],
    '09:05 in Kuala Lumpur' => ['2026-06-01 01:05', true],
    '18:30 in Kuala Lumpur' => ['2026-06-01 10:30', false],
]);

it('counts days in each organisation\'s own time zone', function (): void {
    $dubai = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($dubai, fn () => $dubai->update(['timezone' => 'Asia/Dubai']));
    $dubaiOwner = Tenancy::run($dubai, fn () => Person::factory()->create(['email' => 'omar@globex.test']));
    // 9 June 18:00 UTC is 10 June in Kuala Lumpur but still 9 June in Dubai.
    credentialDueAt('2026-06-09 18:00');
    credentialDueAt('2026-06-09 18:00', ['owner_id' => $dubaiOwner->id], $dubai);
    $this->travelTo(Carbon::parse('2026-06-02 06:00', 'UTC')); // 14:00 in KL, 10:00 in Dubai

    sendReminders();

    expect(sentSteps())->toBe(['upcoming 14'])
        ->and(sentSteps($dubai))->toBe(['upcoming 7']);
});

it('sends one reminder for the current step after days without running', function (): void {
    credentialDueAt('2026-06-21 03:00');
    $this->travelTo(Carbon::parse('2026-06-16 02:00', 'UTC')); // the 14- and 7-day runs were missed

    sendReminders();

    expect(sentSteps())->toBe(['upcoming 7']);
});

it('starts a new cycle once the credential is rotated', function (): void {
    $credential = credentialDueAt('2026-06-08 03:00');
    sendReminders();
    Tenancy::run($this->organisation, fn () => app(RecordRotation::class)->handle($this->admin, $credential));

    $this->travel(1)->days();
    sendReminders();
    $this->travelTo(Carbon::parse('2026-08-25 02:00', 'UTC')); // 7 days before the new due date
    sendReminders();

    expect(sentSteps())->toBe(['upcoming 7', 'upcoming 7'])
        ->and(Tenancy::run($this->organisation, fn () => Reminder::query()->distinct()->count('due_at')))->toBe(2);
});

it('sends nothing for a revoked or deleted credential', function (string $how): void {
    $credential = credentialDueAt('2026-06-08 03:00');
    Tenancy::run($this->organisation, fn () => $how === 'revoked' ? app(RevokeCredential::class)->handle($this->admin, $credential) : $credential->delete());

    sendReminders();

    Notification::assertNothingSent();
})->with(['revoked', 'deleted']);

it('sends each person one digest covering all their due credentials', function (): void {
    credentialDueAt('2026-06-08 03:00', ['name' => 'Deploy token']);
    credentialDueAt('2026-06-05 03:00', ['name' => 'Webhook secret']);

    sendReminders();

    Notification::assertSentOnDemandTimes(CredentialsDue::class, 1);
    Notification::assertSentOnDemand(CredentialsDue::class, fn (CredentialsDue $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'siti@acme.test' && collect($notification->items)->pluck('name')->sort()->values()->all() === ['Deploy token', 'Webhook secret']);
});

it('emails an owner without a login, and shows a member it in the app too, without the hint or where it is used', function (): void {
    $member = memberWithRole($this->organisation, SystemRole::Viewer);
    $memberOwner = Tenancy::run($this->organisation, fn () => Person::factory()->create(['user_id' => $member->id, 'email' => null]));
    credentialDueAt('2026-06-08 03:00');
    credentialDueAt('2026-06-08 03:00', ['owner_id' => $memberOwner->id, 'hint' => '…a9f2', 'used_in' => 'Laravel Cloud prod']);

    sendReminders();

    Notification::assertSentOnDemand(CredentialsDue::class, fn (CredentialsDue $notification, array $channels): bool => $channels === ['mail']);
    Notification::assertSentTo($member, CredentialsDue::class, function (CredentialsDue $notification, array $channels) use ($member): bool {
        $inApp = json_encode($notification->toDatabase($member));

        return $channels === ['mail', 'database'] && ! str_contains($inApp, '…a9f2') && ! str_contains($inApp, 'Laravel Cloud prod');
    });
});

it('falls back to the organisation\'s owners and admins when the owner can\'t be reached', function (Closure $owner): void {
    $admin = memberWithRole($this->organisation, SystemRole::Admin);
    credentialDueAt('2026-06-08 03:00', ['owner_id' => $owner()]);

    sendReminders();

    Notification::assertSentTo([$this->admin, $admin], CredentialsDue::class);
    Notification::assertSentOnDemandTimes(CredentialsDue::class, 0);
})->with([
    'no owner' => fn (): Closure => fn (): null => null,
    'an owner with no email or login' => fn (): Closure => fn () => Tenancy::run(test()->organisation, fn () => Person::factory()->create(['email' => null])->id),
    'a deleted owner' => fn (): Closure => fn () => Tenancy::run(test()->organisation, fn () => tap(Person::factory()->create())->delete()->id),
]);

it('keeps going when one organisation fails', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $globexOwner = Tenancy::run($globex, fn () => Person::factory()->create(['email' => 'omar@globex.test']));
    credentialDueAt('2026-06-08 03:00');
    credentialDueAt('2026-06-08 03:00', ['owner_id' => $globexOwner->id], $globex);
    Credential::retrieved(function (Credential $credential): void {
        if ($credential->organisation_id === test()->organisation->id) {
            throw new RuntimeException('Acme is broken');
        }
    });

    sendReminders();

    expect(sentSteps($globex))->toBe(['upcoming 7']);
});

it('never puts another organisation\'s credentials in a digest', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $globexOwner = Tenancy::run($globex, fn () => Person::factory()->create(['email' => 'siti@acme.test']));
    credentialDueAt('2026-06-08 03:00', ['name' => 'Acme token']);
    credentialDueAt('2026-06-08 03:00', ['owner_id' => $globexOwner->id, 'name' => 'Globex token'], $globex);

    sendReminders();

    Notification::assertSentOnDemandTimes(CredentialsDue::class, 2);
    Notification::assertSentOnDemand(CredentialsDue::class, fn (CredentialsDue $notification): bool => count($notification->items) === 1);
});

it('marks a reminder sent only once delivered, retries unsent ones, and gives up after a day', function (): void {
    $credential = credentialDueAt('2026-06-08 03:00');
    Tenancy::run($this->organisation, fn () => app(ClaimDueReminders::class)->handle(now()));
    $unsent = fn (): int => Tenancy::run($this->organisation, fn (): int => Reminder::query()->whereNull('sent_at')->whereNull('failed_at')->count());
    expect($unsent())->toBe(1);

    $this->travel(31)->minutes();
    sendReminders();
    expect($unsent())->toBe(0)->and(sentSteps())->toBe(['upcoming 7']);

    $stuck = credentialDueAt('2026-06-05 03:00', ['name' => 'Stuck']);
    Tenancy::run($this->organisation, fn () => app(ClaimDueReminders::class)->handle(now()));
    $this->travel(25)->hours();
    Tenancy::run($this->organisation, fn () => app(ClaimDueReminders::class)->handle(now()));

    expect(Tenancy::run($this->organisation, fn (): int => Reminder::query()->where('credential_id', $stuck->id)->whereNotNull('failed_at')->count()))->toBe(1);
});

it('marks reminders failed when delivery fails for good', function (): void {
    credentialDueAt('2026-06-08 03:00');
    $ids = Tenancy::run($this->organisation, fn () => app(ClaimDueReminders::class)->handle(now()));

    Tenancy::run($this->organisation, fn () => new DeliverReminders(array_merge(...array_values($ids)))->failed(new RuntimeException('mail down')));

    expect(Tenancy::run($this->organisation, fn () => Reminder::query()->sole()->failed_at))->not->toBeNull();
});

it('sends no upcoming reminder for a credential a runner rotates, but still says when it is overdue', function (): void {
    onTeamPlan($this->organisation);
    $runner = runnerFor($this->organisation, $this->admin)['runner'];
    $upcoming = credentialDueAt('2026-06-08 03:00');
    $overdue = credentialDueAt('2026-05-29 03:00');

    foreach ([$upcoming, $overdue] as $credential) {
        Tenancy::run($this->organisation, fn () => app(SetAutomaticRotation::class)->handle($this->admin, $credential, automaticRotationData($runner)));
    }

    sendReminders();

    expect(sentSteps())->toBe(['overdue 3']);
});
