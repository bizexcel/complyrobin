<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Actions\CreateNotificationChannel;
use App\Core\Notifications\Actions\DeleteNotificationChannel;
use App\Core\Notifications\Actions\SendTestMessage;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/abc123';
const TEAMS_URL = 'https://prod-12.westus.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?sig=xyz';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

function teamPlanFor(Organisation $organisation, ?Carbon $endsAt = null): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $organisation, Module::Credentials, now()->subDays(30), $endsAt);
}

function addChannel(array $data, ?User $actor = null, ?Organisation $organisation = null): NotificationChannel
{
    $organisation ??= test()->organisation;

    return Tenancy::run($organisation, fn (): NotificationChannel => app(CreateNotificationChannel::class)->handle($actor ?? test()->owner, $data));
}

function testChannel(NotificationChannel $channel, ?Organisation $organisation = null, ?User $actor = null): NotificationChannel
{
    return Tenancy::run($organisation ?? test()->organisation, fn (): NotificationChannel => app(SendTestMessage::class)->handle($actor ?? test()->owner, $channel));
}

it('posts the right message shape to Slack and Teams, and verifies the channel', function (string $type, string $url, Closure $check): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('ok')]);
    $channel = addChannel(['type' => $type, 'name' => '#ops', 'webhook_url' => $url]);

    testChannel($channel);

    expect(Tenancy::run($this->organisation, fn () => $channel->refresh()->verified_at))->not->toBeNull();
    // The HTTP client drops the default :443 when it records the request.
    Http::assertSent(fn (Request $request): bool => $request->url() === str_replace(':443', '', $url) && $check($request->data()));
})->with([
    'Slack' => ['slack', SLACK_URL, fn (array $body): bool => str_contains($body['text'], 'ComplyRobin test message')],
    'Teams' => ['teams', TEAMS_URL, fn (array $body): bool => $body['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'],
]);

it('refuses webhook URLs that could point anywhere else', function (string $type, string $url): void {
    teamPlanFor($this->organisation);

    addChannel(['type' => $type, 'name' => '#ops', 'webhook_url' => $url]);
})->with([
    'plain http' => ['slack', 'http://hooks.slack.com/services/T/B/x'],
    'a look-alike host' => ['slack', 'https://hooks.slack.com.evil.example/services/T/B/x'],
    'user details before the host' => ['slack', 'https://hooks.slack.com@evil.example/services/T/B/x'],
    'the cloud metadata address' => ['slack', 'https://169.254.169.254/latest/meta-data'],
    'localhost' => ['slack', 'https://localhost/services'],
    'another port' => ['slack', 'https://hooks.slack.com:8443/services/T/B/x'],
    'the host only in the query' => ['slack', 'https://evil.example/?u=hooks.slack.com'],
    'a Teams look-alike' => ['teams', 'https://evil.logic.azure.com.attacker.example/workflows/x'],
    'Slack\'s host for Teams' => ['teams', SLACK_URL],
])->throws(ValidationException::class);

it('never follows a redirect', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]);
    $channel = addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);

    expect(fn (): NotificationChannel => testChannel($channel))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
    expect(Tenancy::run($this->organisation, fn () => $channel->refresh()->verified_at))->toBeNull();
});

it('keeps chat channels a Team feature', function (): void {
    addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);
})->throws(ValidationException::class, 'Team');

it('lets only members who manage channels set them up', function (): void {
    teamPlanFor($this->organisation);

    addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL], memberWithRole($this->organisation, SystemRole::Editor));
})->throws(AuthorizationException::class);

it('keeps the webhook URL encrypted and out of the audit trail', function (): void {
    teamPlanFor($this->organisation);
    $this->actingAs($this->owner);
    $channel = addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);

    $stored = DB::connection('pgsql_migrator')->table('notification_channels')->where('id', $channel->id)->value('settings');
    $audited = DB::connection('pgsql_migrator')->table('activities')->where('subject_id', $channel->id)->pluck('attribute_changes')->implode(' ');

    expect($stored)->not->toContain('hooks.slack.com')
        ->and($audited)->toContain('#ops')->not->toContain('hooks.slack.com')->not->toContain('settings')
        ->and(json_encode($channel))->not->toContain('hooks.slack.com');
});

it('limits test messages to five a minute', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('ok')]);
    $channel = addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);

    foreach (range(1, 5) as $attempt) {
        testChannel($channel);
    }

    testChannel($channel);
})->throws(ValidationException::class, 'Too many');

it('never shows what the chat service replied', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('internal stack trace: token=very-secret', 500)]);
    $channel = addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);

    try {
        testChannel($channel);
        $this->fail('The test message did not fail.');
    } catch (ValidationException $exception) {
        expect(json_encode($exception->errors()))->toContain('HTTP 500')->not->toContain('very-secret');
    }
});

it('allows at most 10 channels', function (): void {
    teamPlanFor($this->organisation);
    foreach (range(1, 10) as $number) {
        addChannel(['type' => 'slack', 'name' => "#channel-{$number}", 'webhook_url' => SLACK_URL]);
    }

    addChannel(['type' => 'slack', 'name' => '#one-too-many', 'webhook_url' => SLACK_URL]);
})->throws(ValidationException::class, 'at most 10');

it('sends Team reminders to tested channels, pauses them after a downgrade, and still lets them be deleted', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    teamPlanFor($this->organisation, now()->addDays(2));
    Http::fake(['*' => Http::response('ok')]);
    $channel = testChannel(addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]));
    Tenancy::run($this->organisation, function (): void {
        $owner = Person::factory()->create(['email' => 'siti@acme.test']);
        Credential::factory()->dueIn(7)->create(['application_id' => Application::factory()->create()->id, 'owner_id' => $owner->id]);
    });

    $this->artisan('credentials:send-reminders')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'due for rotation'));

    $this->travel(6)->days();
    app(Entitlements::class)->forget($this->organisation);
    Http::fake(['*' => Http::response('ok')]);
    $this->artisan('credentials:send-reminders')->assertSuccessful();
    Http::assertNothingSent();

    Tenancy::run($this->organisation, fn () => app(DeleteNotificationChannel::class)->handle($this->owner, $channel));
    expect(Tenancy::run($this->organisation, fn (): int => NotificationChannel::query()->count()))->toBe(0);
});

it('never posts one organisation\'s reminders to another\'s channel', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    $globexOwner = User::factory()->create();
    $globex = app(CreateOrganisation::class)->handle($globexOwner, 'Globex');
    teamPlanFor($this->organisation);
    teamPlanFor($globex);
    Http::fake(['*' => Http::response('ok')]);
    testChannel(addChannel(['type' => 'slack', 'name' => '#acme', 'webhook_url' => SLACK_URL]));
    testChannel(addChannel(['type' => 'slack', 'name' => '#globex', 'webhook_url' => 'https://hooks.slack.com/services/GLOBEX/B/xyz'], $globexOwner, $globex), $globex, $globexOwner);
    Tenancy::run($this->organisation, fn () => Credential::factory()->dueIn(7)->create([
        'application_id' => Application::factory()->create()->id, 'owner_id' => Person::factory()->create(['email' => 'siti@acme.test'])->id,
    ]));

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'GLOBEX') && str_contains((string) ($request->data()['text'] ?? ''), 'due for rotation'));
    Http::assertSent(fn (Request $request): bool => $request->url() === SLACK_URL && str_contains((string) ($request->data()['text'] ?? ''), 'Acme'));
});

it('refuses a reminder with no recipient or with two', function (Closure $recipients): void {
    teamPlanFor($this->organisation);
    $channel = addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]);
    $credential = Tenancy::run($this->organisation, fn () => Credential::factory()->create(['application_id' => Application::factory()->create()->id])->refresh());
    $person = Tenancy::run($this->organisation, fn () => Person::factory()->create());

    DB::connection('pgsql_migrator')->table('reminders')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $this->organisation->id, 'credential_id' => $credential->id,
        'kind' => 'upcoming', 'audience' => 'owner', 'offset_days' => 7, 'due_at' => $credential->next_rotation_at,
        ...$recipients($person, $channel),
    ]);
})->with([
    'no recipient' => fn (): array => [],
    'a person and a channel' => fn ($person, $channel): array => ['person_id' => $person->id, 'notification_channel_id' => $channel->id],
])->throws(QueryException::class, 'reminders_one_recipient');
