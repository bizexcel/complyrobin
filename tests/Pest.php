<?php

use App\Billing\Airwallex\WebhookSignature;
use App\Billing\SubscriptionItem;
use App\Core\Access\Enums\Ability;
use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\StaffRoleChange;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Actions\CreateNotificationChannel;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\InviteMember;
use App\Core\Organisations\Models\Organisation;
use App\Core\Organisations\Notifications\InvitationNotification;
use App\Core\People\Models\Department;
use App\Core\People\Models\Person;
use App\Core\Tasks\Actions\CreateTask;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\ClaimDueReminders;
use App\Modules\Credentials\Actions\ClaimRotationRuns;
use App\Modules\Credentials\Actions\CreateCalendarFeed;
use App\Modules\Credentials\Actions\CreateRunner;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Actions\RequestRotation;
use App\Modules\Credentials\Actions\SetAutomaticRotation;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\Runner;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Arch');

/**
 * Every concrete Eloquent model class under app/, discovered from the PSR-4 path.
 *
 * @return list<class-string<Model>>
 */
function appModels(): array
{
    $models = [];

    foreach (File::allFiles(app_path()) as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! new ReflectionClass($class)->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

/**
 * A new member of the organisation with the given role.
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

/**
 * The latest invitation link emailed to the address.
 */
function invitationLink(string $email): string
{
    $link = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$link): bool {
        if ($notifiable->routes['mail'] !== $email) {
            return false;
        }

        $link = $notification->url;

        return true;
    });

    return $link;
}

/**
 * An organisation with at least one row in every tenant table, made through the real actions where they exist.
 * A new tenant table fails the coverage test below until it gets a row here.
 */
function populatedOrganisation(string $name): Organisation
{
    Notification::fake();
    Storage::fake('local');
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, $name);
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    app(GrantModule::class)->handle($staff, $organisation, Module::Credentials, now()->subDay());

    test()->actingAs($owner);

    Tenancy::run($organisation, function () use ($owner, $organisation): void {
        $owner->givePermissionTo(Ability::ManagePeople->value);
        app(InviteMember::class)->handle($owner, Str::lower(Str::random(8)).'@example.com', 'Editor');
        $department = Department::factory()->create();
        $person = Person::factory()->create(['department_id' => $department->id]);
        app(CreateTask::class)->handle($owner, ['title' => 'Rotate a key', 'assignee_id' => $person->id], $person);
        $application = Application::factory()->create(['owner_id' => $person->id]);
        $credential = Credential::factory()->create(['application_id' => $application->id, 'owner_id' => $person->id]);
        app(RecordRotation::class)->handle($owner, $credential, [
            'notes' => 'Rotated for the fixture',
            'evidence' => [evidencePdf()],
        ]);
        Credential::factory()->dueIn(5)->create(['application_id' => $application->id, 'owner_id' => $person->id]);
        app(CreateNotificationChannel::class)->handle($owner, ['type' => 'slack', 'name' => '#ops', 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX']);
        app(ClaimDueReminders::class)->handle(now());
        app(CreateCalendarFeed::class)->handle($owner);
        ['runner' => $runner, 'token' => $token] = app(CreateRunner::class)->handle($owner, ['name' => 'GitHub']);
        app(SetAutomaticRotation::class)->handle($owner, $credential, automaticRotationData($runner));
        app(RequestRotation::class)->handle($owner, $credential);
        app(ClaimRotationRuns::class)->handle($organisation, $token);
        SubscriptionItem::factory()->create();

    });

    return $organisation;
}

/**
 * Act as the user inside the organisation, as the panel's middleware would.
 */
function openOrganisationAs(User $user, Organisation $organisation): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($organisation);
    Tenancy::set($organisation);
}

/**
 * A small but genuine PDF, as evidence uploads are checked by their content.
 */
function evidencePdf(string $name = 'evidence.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/**
 * Every recorded staff role change, oldest first. Read as staff do, on the platform connection: the app's own login
 * can't read them.
 *
 * @return EloquentCollection<int, StaffRoleChange>
 */
function staffRoleChanges(): EloquentCollection
{
    return Tenancy::bypass('test: read the staff role changes', fn (): EloquentCollection => StaffRoleChange::query()->orderBy('created_at')->orderBy('id')->get());
}

/**
 * Run the callback as the staff member on the staff panel, as RunAsPlatform runs each of its requests.
 *
 * @template TReturn
 *
 * @param  Closure(): TReturn  $callback
 * @return TReturn
 */
function asStaff(User $staff, Closure $callback): mixed
{
    test()->actingAs($staff);
    Filament::setCurrentPanel('admin');

    return Tenancy::bypass('test: staff panel', $callback);
}

/**
 * Put the organisation on Team (the credentials module), optionally until a given time.
 */
function onTeamPlan(Organisation $organisation, ?Carbon $endsAt = null): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $organisation, Module::Credentials, now()->subDays(200), $endsAt);
}

/**
 * A rotation runner made through CreateRunner by the actor, with the token it was given.
 *
 * @return array{runner: Runner, token: string}
 */
function runnerFor(Organisation $organisation, User $actor, string $name = 'GitHub'): array
{
    return Tenancy::run($organisation, fn (): array => app(CreateRunner::class)->handle($actor, ['name' => $name]));
}

/**
 * Automatic rotation settings as the runner contract's example has them. Overrides use dot notation, and a null
 * override removes the key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function automaticRotationData(Runner $runner, array $overrides = []): array
{
    $data = [
        'runner_id' => $runner->id,
        'source' => 'openai',
        'source_settings' => ['project_id' => 'proj_abc', 'service_account_id' => 'svc_acct_abc', 'key_lifetime_days' => null],
        'destinations' => [
            ['type' => 'laravel_cloud', 'secret_id' => 'sec-1', 'secret_name' => 'OPENAI_API_KEY', 'environment_ids' => ['env-1'], 'redeploy' => true],
        ],
    ];

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            Arr::forget($data, $key);
        } else {
            data_set($data, $key, $value);
        }
    }

    return $data;
}

/**
 * A credential named "OpenAI production key", due in this many days (negative: overdue), rotated by the runner.
 *
 * @param  array<string, mixed>  $attributes
 */
function automatedCredential(Organisation $organisation, User $actor, Runner $runner, int $dueInDays = 0, array $attributes = []): Credential
{
    return Tenancy::run($organisation, function () use ($actor, $runner, $dueInDays, $attributes): Credential {
        $credential = Credential::factory()->dueIn($dueInDays)->create([
            'application_id' => Application::factory()->create()->id,
            'name' => 'OpenAI production key',
            ...$attributes,
        ]);
        app(SetAutomaticRotation::class)->handle($actor, $credential, automaticRotationData($runner));

        return $credential->refresh();
    });
}

/**
 * A response body recorded from the Airwallex demo API, in tests/Fixtures/airwallex (see requests.jsonl there for the
 * request that produced it).
 *
 * @return array<string, mixed>
 */
function airwallexFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/Fixtures/airwallex/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fake the Airwallex demo API with recorded responses and refuse any other request. The login is always answered.
 *
 * @param  array<string, array{0: string, 1?: int}>  $routes  path under /api/v1/billing/ => [fixture name, status]
 */
function fakeAirwallex(array $routes): void
{
    config(['billing.client_id' => 'test-client', 'billing.api_key' => 'test-key', 'billing.env' => 'demo']);

    Http::preventStrayRequests();
    Http::fake([
        'api-demo.airwallex.com/api/v1/authentication/login' => Http::response(airwallexFixture('authentication.login'), 201),
        ...collect($routes)->mapWithKeys(fn (array $route, string $path): array => [
            "api-demo.airwallex.com/api/v1/billing/{$path}" => Http::response(airwallexFixture($route[0]), $route[1] ?? 200),
        ])->all(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function airwallexEvent(array $overrides = []): array
{
    return [
        'id' => 'evt_'.Str::random(24),
        'name' => 'invoice.created',
        'account_id' => 'acct_test',
        'data' => ['object' => ['id' => 'inv_test']],
        'created_at' => now()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * Post the event as Airwallex would: the raw body, signed with the timestamp in milliseconds.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?string $secret = 'whsec_test', ?int $timestampMs = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) ($timestampMs ?? now()->getTimestampMs());

    return test()->call('POST', route('cashier.webhook'), server: [
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_SIGNATURE' => $secret === null ? '' : WebhookSignature::computeSignature($timestamp, $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}
