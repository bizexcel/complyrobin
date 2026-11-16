<?php

use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/*
 * Requests sent, one after another, through a real Octane worker running this app: Octane's own request handling,
 * with only the web server faked. Under Octane the app stays in memory, so each test checks what the next request
 * sees after the one before it.
 */

/**
 * Start one Octane worker on this test's app. Call the result with a path (and cookies) to send it a GET request.
 *
 * @return Closure(string, array<string, string>=): Response
 */
function octaneWorker(): Closure
{
    $worker = new FakeWorker(octaneApplicationFactory(), $client = new FakeClient([]));
    $worker->boot();

    return function (string $path, array $cookies = []) use ($worker, $client): Response {
        $client->requests = [Request::create($path, 'GET', cookies: $cookies)];
        $worker->run();

        if ($client->errors !== []) {
            throw new RuntimeException(implode("\n", $client->errors));
        }

        return array_pop($client->responses);
    };
}

/**
 * Hands Octane this test's app, already pointed at the test database, instead of booting a second one.
 */
function octaneApplicationFactory(): ApplicationFactory
{
    return new class(app()) extends ApplicationFactory
    {
        public function __construct(private readonly Application $application)
        {
            parent::__construct($application->basePath());
        }

        #[Override]
        public function createApplication(array $initialInstances = []): Application
        {
            foreach ($initialInstances as $abstract => $instance) {
                $this->application->instance($abstract, $instance);
            }

            return $this->warm($this->application);
        }
    };
}

/**
 * The cookies a response set, to send back as that browser's next request.
 *
 * @return array<string, string>
 */
function cookiesFrom(Response $response): array
{
    return collect($response->headers->getCookies())
        ->mapWithKeys(fn (Cookie $cookie): array => [$cookie->getName() => (string) $cookie->getValue()])
        ->all();
}

/**
 * Probe routes: what a request's tenancy looks like from inside it, in PHP and to row-level security.
 */
beforeEach(function (): void {
    $report = fn (): array => [
        'signed_in' => Auth::id(),
        ...(array) DB::connection('pgsql')->selectOne(<<<'SQL'
            select nullif(current_setting('app.organisation_id', true), '') as organisation,
                   nullif(current_setting('app.user_id', true), '') as "user"
            SQL),
        'bypassing' => Tenancy::isBypassing(),
        'connection' => DB::getDefaultConnection(),
    ];

    Route::middleware('web')->group(function () use ($report): void {
        Route::get('/_probe', $report);
        Route::get('/_probe/sign-in/{user}', function (string $user) use ($report): array {
            Auth::login(User::query()->findOrFail($user));

            return $report();
        });
        // A badly behaved request: it enters an organisation and never leaves it.
        Route::get('/_probe/enter/{organisation}', function (string $organisation) use ($report): array {
            Tenancy::set($organisation);

            return $report();
        });
    });
});

it('keeps Octane\'s own /octane paths out of organisation slugs', function (): void {
    expect(app(CreateOrganisation::class)->handle(User::factory()->create(), 'Octane')->slug)->toBe('octane-2');
});

it('runs a guest request as nobody after a signed-in user\'s request on the same worker', function (): void {
    $user = User::factory()->create();
    $send = octaneWorker();

    $signedIn = json_decode((string) $send("/_probe/sign-in/{$user->id}")->getContent(), true);
    $guest = json_decode((string) $send('/_probe')->getContent(), true);

    expect([$signedIn['user'], $guest])->toBe([
        $user->id,
        ['signed_in' => null, 'organisation' => null, 'user' => null, 'bypassing' => false, 'connection' => 'pgsql'],
    ]);
});

it('starts the next request outside any organisation, even when the last one never left its own', function (): void {
    $organisation = Organisation::factory()->create();
    $send = octaneWorker();

    $inside = json_decode((string) $send("/_probe/enter/{$organisation->id}")->getContent(), true);
    $next = json_decode((string) $send('/_probe')->getContent(), true);

    expect([$inside['organisation'], $next['organisation']])->toBe([$organisation->id, null]);
});

it('keeps two organisations\' members apart when their requests alternate on one worker', function (): void {
    [$aminah, $gopal] = User::factory()->count(2)->create();
    $acme = app(CreateOrganisation::class)->handle($aminah, 'Acme');
    $globex = app(CreateOrganisation::class)->handle($gopal, 'Globex');
    Tenancy::run($acme, fn () => Person::factory()->create(['name' => 'Acme Accountant']));
    Tenancy::run($globex, fn () => Person::factory()->create(['name' => 'Globex Geologist']));
    $send = octaneWorker();
    $asAminah = cookiesFrom($send("/_probe/sign-in/{$aminah->id}"));
    $asGopal = cookiesFrom($send("/_probe/sign-in/{$gopal->id}"));

    $responses = [
        'Aminah opens Acme' => $send("/{$acme->slug}/people", $asAminah),
        'Gopal opens Globex' => $send("/{$globex->slug}/people", $asGopal),
        'Gopal tries Acme' => $send("/{$acme->slug}/people", $asGopal),
        'a guest tries Acme' => $send("/{$acme->slug}/people"),
        'Aminah opens Acme again' => $send("/{$acme->slug}/people", $asAminah),
    ];

    expect(array_map(fn (Response $response): array => [
        $response->getStatusCode(),
        str_contains((string) $response->getContent(), 'Acme Accountant'),
        str_contains((string) $response->getContent(), 'Globex Geologist'),
    ], $responses))->toBe([
        'Aminah opens Acme' => [200, true, false],
        'Gopal opens Globex' => [200, false, true],
        'Gopal tries Acme' => [404, false, false],
        'a guest tries Acme' => [302, false, false],
        'Aminah opens Acme again' => [200, true, false],
    ]);
});

it('ends a staff member\'s access to every organisation with their request', function (): void {
    $staff = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $gopal = User::factory()->create();
    app(CreateOrganisation::class)->handle(User::factory()->create(), 'Acme');
    $globex = app(CreateOrganisation::class)->handle($gopal, 'Globex');
    $send = octaneWorker();
    $asStaff = cookiesFrom($send("/_probe/sign-in/{$staff->id}"));
    $asGopal = cookiesFrom($send("/_probe/sign-in/{$gopal->id}"));

    $staffPanel = $send('/admin/organisations', $asStaff);
    $next = json_decode((string) $send('/_probe', $asGopal)->getContent(), true);
    $customerPanel = $send("/{$globex->slug}", $asGopal);

    expect([
        $staffPanel->getStatusCode(),
        str_contains((string) $staffPanel->getContent(), 'Acme') && str_contains((string) $staffPanel->getContent(), 'Globex'),
        $next,
        $customerPanel->getStatusCode(),
        $send('/admin/organisations', $asGopal)->getStatusCode(),
    ])->toBe([
        200,
        true,
        ['signed_in' => $gopal->id, 'organisation' => null, 'user' => $gopal->id, 'bypassing' => false, 'connection' => 'pgsql'],
        200,
        403,
    ]);
});

it('starts every request, task and tick clean, even when the worker\'s own app was left inside an organisation', function (): void {
    $organisation = Organisation::factory()->create();
    $worker = new FakeWorker(octaneApplicationFactory(), $client = new FakeClient([]));
    $worker->boot();
    Tenancy::set($organisation);
    Tenancy::setUser(User::factory()->create()->id);
    $seen = [];

    $client->requests = [Request::create('/_probe')];
    $worker->run();
    $seen['request'] = json_decode((string) $client->responses[0]->getContent(), true)['organisation'];
    Tenancy::set($organisation);
    $client->requests = [fn (): bool => Tenancy::check() || Tenancy::userId() !== null];
    $seen['task'] = $worker->runTasks()[0]->result;
    Tenancy::set($organisation);
    Event::listen(TickReceived::class, function () use (&$seen): void {
        $seen['tick'] = Tenancy::check();
    });
    $worker->runTicks();

    expect($seen)->toBe(['request' => null, 'task' => false, 'tick' => false]);
});
