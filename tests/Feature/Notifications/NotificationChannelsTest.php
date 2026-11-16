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
use App\Core\Notifications\Actions\UpdateNotificationChannel;
use App\Core\Notifications\Actions\VerifyNotificationChannel;
use App\Core\Notifications\Models\NotificationChannel;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Resources\NotificationChannels\NotificationChannelResource;
use App\Filament\App\Resources\NotificationChannels\Pages\CreateNotificationChannel as CreateNotificationChannelPage;
use App\Filament\App\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Modules\Credentials\Jobs\DeliverReminders;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Reminder;
use App\Modules\Credentials\Notifications\CredentialsDue;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/abc123';
const TELEGRAM_TOKEN = '123456789:TEST-bot-token';
const TELEGRAM_CHAT = '-1001234567890';
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

function telegramChannel(?Organisation $organisation = null, ?User $actor = null): NotificationChannel
{
    config(['services.telegram.bot_token' => TELEGRAM_TOKEN]);

    return addChannel(['type' => 'telegram', 'name' => 'Ops group', 'chat_id' => TELEGRAM_CHAT], $actor, $organisation);
}

/**
 * The code the bot last sent, read from the recorded request.
 */
function sentTelegramCode(): string
{
    $request = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
        ->last(fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'Your ComplyRobin code'));

    expect($request)->not->toBeNull();
    preg_match('/\b(\d{6})\b/', (string) $request->data()['text'], $matches);

    return $matches[1];
}

function enterCode(NotificationChannel $channel, string $code): NotificationChannel
{
    return Tenancy::run(test()->organisation, fn (): NotificationChannel => app(VerifyNotificationChannel::class)->handle(test()->owner, $channel, $code));
}

it('sends Telegram a code through the bot, and confirms the chat only when the code is typed back', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = telegramChannel();

    testChannel($channel);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'
        && $request->data()['chat_id'] === TELEGRAM_CHAT);
    $code = sentTelegramCode();
    expect(Tenancy::run($this->organisation, fn () => $channel->refresh()->verified_at))->toBeNull()
        ->and(Cache::get("channel-code:{$channel->id}"))->toBe(hash('sha256', $code));

    enterCode($channel, $code);

    expect(Tenancy::run($this->organisation, fn () => $channel->refresh()->verified_at))->not->toBeNull()
        ->and(Cache::has("channel-code:{$channel->id}"))->toBeFalse();
});

it('never sends reminders to a Telegram chat until its code is entered', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = testChannel(telegramChannel());
    Tenancy::run($this->organisation, fn () => Credential::factory()->dueIn(7)->create([
        'application_id' => Application::factory()->create()->id, 'owner_id' => Person::factory()->create(['email' => 'siti@acme.test'])->id,
    ]));
    $isReminder = fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'due for rotation');

    $this->artisan('credentials:send-reminders')->assertSuccessful();
    Http::assertNotSent($isReminder);

    enterCode($channel, sentTelegramCode());
    $this->travel(1)->hour();
    $this->artisan('credentials:send-reminders')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => $isReminder($request) && $request->data()['chat_id'] === TELEGRAM_CHAT);
});

it('locks the code after five wrong tries', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = telegramChannel();
    testChannel($channel);
    $code = sentTelegramCode();
    $wrong = $code === '111111' ? '222222' : '111111';

    foreach (range(1, 5) as $attempt) {
        expect(fn (): NotificationChannel => enterCode($channel, $wrong))->toThrow(ValidationException::class, 'isn\'t right');
    }

    expect(fn (): NotificationChannel => enterCode($channel, $code))->toThrow(ValidationException::class, 'tried too often')
        ->and(Tenancy::run($this->organisation, fn () => $channel->refresh()->verified_at))->toBeNull();
});

it('expires the code after 10 minutes', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = telegramChannel();
    testChannel($channel);
    $code = sentTelegramCode();

    $this->travel(11)->minutes();

    enterCode($channel, $code);
})->throws(ValidationException::class, 'expired');

it('sends at most three codes an hour', function (): void {
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = telegramChannel();

    foreach (range(1, 3) as $attempt) {
        testChannel($channel);
    }

    testChannel($channel);
})->throws(ValidationException::class, 'Too many codes');

it('never lets the bot token reach an error or the logs', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response(['ok' => true])]);
    $channel = testChannel(telegramChannel());
    enterCode($channel, sentTelegramCode());
    Tenancy::run($this->organisation, fn () => Credential::factory()->dueIn(7)->create(['application_id' => Application::factory()->create()->id]));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.($event->context['exception'] ?? '');
    });
    Http::fake(fn (): never => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'));

    $error = rescue(fn (): NotificationChannel => testChannel($channel), fn (Throwable $exception): string => json_encode($exception->errors()), report: false);
    $this->artisan('credentials:send-reminders')->assertSuccessful();

    expect($error)->toContain('Couldn\'t reach Telegram')->not->toContain(TELEGRAM_TOKEN)
        ->and($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain(TELEGRAM_TOKEN)
        ->and(implode("\n", $logged))->not->toContain('TEST-bot-token');
});

it('offers Telegram only once the bot is set up', function (): void {
    teamPlanFor($this->organisation);
    config(['services.telegram.bot_token' => null]);

    addChannel(['type' => 'telegram', 'name' => 'Ops group', 'chat_id' => TELEGRAM_CHAT]);
})->throws(ValidationException::class, 'isn\'t available');

it('accepts only a Telegram chat id for a Telegram channel', function (array $data): void {
    teamPlanFor($this->organisation);
    config(['services.telegram.bot_token' => TELEGRAM_TOKEN]);

    addChannel(['type' => 'telegram', 'name' => 'Ops group', ...$data]);
})->with([
    'a username' => [['chat_id' => '@acme_ops']],
    'a link' => [['chat_id' => 'https://t.me/acme_ops']],
    'too short' => [['chat_id' => '123']],
    'a webhook URL instead' => [['chat_id' => TELEGRAM_CHAT, 'webhook_url' => SLACK_URL]],
])->throws(ValidationException::class);

it('sets up a Telegram chat from the channels page', function (): void {
    teamPlanFor($this->organisation);
    config(['services.telegram.bot_token' => TELEGRAM_TOKEN]);
    Http::fake(['*' => Http::response(['ok' => true])]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(CreateNotificationChannelPage::class)
        ->fillForm(['type' => 'telegram'])
        ->assertFormFieldHidden('webhook_url')
        ->fillForm(['name' => 'Ops group', 'chat_id' => TELEGRAM_CHAT])
        ->call('create')
        ->assertHasNoFormErrors();
    $channel = NotificationChannel::query()->sole();

    Livewire::test(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->callAction(TestAction::make('verify')->table($channel), ['code' => sentTelegramCode()])
        ->assertHasNoFormErrors();

    expect($channel->refresh())->verified_at->not->toBeNull()->settings->toBe(['chat_id' => TELEGRAM_CHAT]);
});

it('hides Telegram from the channel types until the bot is set up', function (): void {
    teamPlanFor($this->organisation);
    config(['services.telegram.bot_token' => null]);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(CreateNotificationChannelPage::class)
        ->fillForm(['type' => 'telegram', 'name' => 'Ops group', 'chat_id' => TELEGRAM_CHAT])
        ->call('create')
        ->assertHasFormErrors(['type']);
});

it('offers no chat channels until CHAT_CHANNELS switches them on', function (): void {
    teamPlanFor($this->organisation);
    config(['services.chat_channels.enabled' => []]);
    openOrganisationAs($this->owner, $this->organisation);

    expect(NotificationChannelResource::canAccess())->toBeFalse()
        ->and(fn (): NotificationChannel => addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]))->toThrow(ValidationException::class, 'isn\'t available');

    config(['services.chat_channels.enabled' => ['slack']]);

    expect(NotificationChannelResource::canAccess())->toBeTrue()
        ->and(addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL])->type->value)->toBe('slack')
        ->and(fn (): NotificationChannel => addChannel(['type' => 'teams', 'name' => 'Ops', 'webhook_url' => TEAMS_URL]))->toThrow(ValidationException::class, 'isn\'t available');
});

it('pauses a saved channel while its type is switched off, and resumes when it is back on', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('ok')]);
    $channel = testChannel(addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]));
    Tenancy::run($this->organisation, fn () => Credential::factory()->dueIn(7)->create([
        'application_id' => Application::factory()->create()->id, 'owner_id' => Person::factory()->create(['email' => 'siti@acme.test'])->id,
    ]));
    $isReminder = fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'due for rotation');
    config(['services.chat_channels.enabled' => []]);

    $this->artisan('credentials:send-reminders')->assertSuccessful();

    Http::assertNotSent($isReminder);
    Notification::assertSentOnDemand(CredentialsDue::class);
    expect(Tenancy::run($this->organisation, fn (): int => Reminder::query()->whereNotNull('notification_channel_id')->count()))->toBe(0);
    expect(fn (): NotificationChannel => testChannel($channel))->toThrow(ValidationException::class, 'isn\'t available')
        ->and(fn () => Tenancy::run($this->organisation, fn () => app(UpdateNotificationChannel::class)
            ->handle($this->owner, $channel->refresh(), ['name' => '#ops', 'webhook_url' => SLACK_URL])))->toThrow(ValidationException::class, 'isn\'t available');

    config(['services.chat_channels.enabled' => ['slack']]);
    $this->travel(1)->hour();
    $this->artisan('credentials:send-reminders')->assertSuccessful();

    Http::assertSent($isReminder);
});

it('doesn\'t deliver a reminder already claimed for a channel whose type was switched off since', function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 02:00', 'UTC'));
    teamPlanFor($this->organisation);
    Http::fake(['*' => Http::response('ok')]);
    testChannel(addChannel(['type' => 'slack', 'name' => '#ops', 'webhook_url' => SLACK_URL]));
    Tenancy::run($this->organisation, fn () => Credential::factory()->dueIn(7)->create(['application_id' => Application::factory()->create()->id]));
    Queue::fake();
    $this->artisan('credentials:send-reminders')->assertSuccessful();
    $job = Queue::pushed(DeliverReminders::class)
        ->first(fn ($job): bool => Tenancy::run($this->organisation, fn (): bool => Reminder::query()->whereKey($job->reminderIds)->whereNotNull('notification_channel_id')->exists()));

    config(['services.chat_channels.enabled' => []]);
    Tenancy::run($this->organisation, fn () => app()->call([$job, 'handle']));

    Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'due for rotation'));
});
