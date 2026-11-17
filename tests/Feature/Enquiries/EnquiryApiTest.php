<?php

use App\Core\Enquiries\Models\Enquiry;
use App\Core\Enquiries\Rules\Turnstile;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.turnstile.secret_key' => 'turnstile-test-secret']);
    Http::preventStrayRequests();
});

function fakeTurnstile(bool $success): void
{
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => $success, 'error-codes' => $success ? [] : ['invalid-input-response']])]);
}

/**
 * @return array<string, string>
 */
function enquiry(array $overrides = []): array
{
    return [
        'name' => 'Aisyah Rahman',
        'email' => 'Aisyah@Example.com',
        'company' => 'Kedai Kopi Sdn Bhd',
        'message' => 'Do you support Telegram reminders?',
        'cf-turnstile-response' => 'token-from-the-widget',
        ...$overrides,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function storedEnquiries(): array
{
    return Tenancy::bypass('test: read enquiries', fn (): array => Enquiry::query()->get(['name', 'email', 'company', 'message', 'handled_at'])
        ->map(fn (Enquiry $enquiry): array => $enquiry->only(['name', 'email', 'company', 'message', 'handled_at']))->all());
}

it('records an enquiry from the www contact form once Turnstile confirms a person sent it', function (): void {
    fakeTurnstile(true);

    $this->postJson('/api/enquiries', enquiry(), ['Origin' => 'https://www.complyrobin.com'])
        ->assertCreated()
        ->assertHeader('Access-Control-Allow-Origin', 'https://www.complyrobin.com')
        ->assertJson(['message' => 'Thank you. We have your message and will reply by email.']);

    expect(storedEnquiries())->toBe([[
        'name' => 'Aisyah Rahman',
        'email' => 'aisyah@example.com',
        'company' => 'Kedai Kopi Sdn Bhd',
        'message' => 'Do you support Telegram reminders?',
        'handled_at' => null,
    ]]);

    Http::assertSent(fn (Request $request): bool => $request->url() === Turnstile::VERIFY_URL
        && $request['secret'] === 'turnstile-test-secret'
        && $request['response'] === 'token-from-the-widget');
});

it('refuses an enquiry Turnstile does not confirm', function (array $overrides, ?bool $turnstileSays): void {
    if ($turnstileSays !== null) {
        fakeTurnstile($turnstileSays);
    }

    $this->postJson('/api/enquiries', enquiry($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cf-turnstile-response']);

    expect(storedEnquiries())->toBe([]);
})->with([
    'rejected token' => [[], false],
    'missing token' => [['cf-turnstile-response' => ''], null],
]);

it('refuses every enquiry when no Turnstile secret is set', function (): void {
    config(['services.turnstile.secret_key' => null]);

    $this->postJson('/api/enquiries', enquiry())->assertUnprocessable()->assertJsonValidationErrors(['cf-turnstile-response']);

    expect(storedEnquiries())->toBe([]);
    Http::assertNothingSent();
});

it('refuses an enquiry with a missing or bad field', function (array $overrides, string $field): void {
    fakeTurnstile(true);

    $this->postJson('/api/enquiries', enquiry($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(storedEnquiries())->toBe([]);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'no message' => [['message' => ''], 'message'],
    'message too long' => [['message' => str_repeat('a', 5001)], 'message'],
]);

it('lets only www call the API from a browser', function (string $origin, bool $allowed): void {
    $response = $this->call('OPTIONS', '/api/enquiries', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($allowed ? $origin : null);
})->with([
    'www' => ['https://www.complyrobin.com', true],
    'another site' => ['https://evil.example', false],
    'plain http www' => ['http://www.complyrobin.com', false],
]);

it('rate limits enquiries from one address', function (): void {
    fakeTurnstile(true);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/enquiries', enquiry())->assertCreated();
    }

    $this->postJson('/api/enquiries', enquiry())->assertTooManyRequests();
    expect(storedEnquiries())->toHaveCount(5);
});

it('lets the app add enquiries but never read or change them', function (string $statement): void {
    DB::connection('pgsql')->statement($statement);
})->with([
    'select' => 'select * from enquiries',
    'update' => "update enquiries set note = 'x'",
    'delete' => 'delete from enquiries',
])->throws(QueryException::class, 'permission denied');
