<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Actions\RenameOrganisationSlug;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\RevokeRunner;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
    ['runner' => $this->runner, 'token' => $this->token] = runnerFor($this->organisation, $this->owner);
    $this->credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
});

function claimOverHttp(?string $token = null, ?string $slug = null): TestResponse
{
    $request = test()->withHeaders($token === '' ? [] : ['Authorization' => 'Bearer '.($token ?? test()->token)]);

    return $request->postJson(route('runner.claims', ['organisation' => $slug ?? test()->organisation->slug]));
}

/**
 * @param  array<string, mixed>  $report
 */
function reportOverHttp(array $report, ?string $token = null, ?Organisation $organisation = null): TestResponse
{
    return test()->withToken($token ?? test()->token)
        ->postJson(route('runner.reports', ['organisation' => ($organisation ?? test()->organisation)->slug]), $report);
}

it('gives a runner its due work over HTTP, without caching or cookies', function (): void {
    $response = claimOverHttp();

    $response->assertOk()
        ->assertJsonPath('jobs.0.credential.reference', $this->credential->reference)
        ->assertJsonPath('jobs.0.destinations.0.secret_name', 'OPENAI_API_KEY');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('serves the API under /api/v1 on the app\'s own address by default', function (): void {
    expect(route('runner.claims', ['organisation' => 'acme'], absolute: false))->toBe('/api/v1/runner/acme/claims');
});

it('records a report over HTTP', function (): void {
    $run = claimOverHttp()->json('jobs.0.run');

    reportOverHttp([
        'run' => $run, 'outcome' => 'succeeded', 'rotated_at' => now()->toIso8601String(),
        'new_key' => ['id' => 'key_abc', 'hint' => 'AB12'], 'deployments' => ['dep-1'],
    ])->assertOk()->assertExactJson(['recorded' => true]);
});

it('answers 401 for a missing, malformed, unknown or revoked token, and an unknown organisation', function (string $case): void {
    $response = match ($case) {
        'missing' => claimOverHttp(''),
        'malformed' => claimOverHttp('not-a-token'),
        'unknown' => claimOverHttp('crr_'.str_repeat('c', 60)),
        'unknown organisation' => claimOverHttp(slug: 'nobody-here'),
        'revoked' => (function (): TestResponse {
            Tenancy::run(test()->organisation, fn () => app(RevokeRunner::class)->handle(test()->owner, test()->runner));

            return claimOverHttp();
        })(),
    };

    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with(['missing', 'malformed', 'unknown', 'unknown organisation', 'revoked']);

it('answers 401 when one organisation\'s token is used on another', function (): void {
    $otherOwner = User::factory()->create();
    $other = app(CreateOrganisation::class)->handle($otherOwner, 'Globex');

    claimOverHttp(slug: $other->slug)->assertUnauthorized();
});

it('answers 404 when another organisation\'s run is reported', function (): void {
    $run = claimOverHttp()->json('jobs.0.run');
    $otherOwner = User::factory()->create();
    $other = app(CreateOrganisation::class)->handle($otherOwner, 'Globex');
    onTeamPlan($other);
    ['token' => $otherToken] = runnerFor($other, $otherOwner);

    reportOverHttp(['run' => $run, 'outcome' => 'failed', 'step' => 'push', 'message' => 'x'], $otherToken, $other)->assertNotFound();
});

it('answers 422 with errors by field for a bad report', function (): void {
    reportOverHttp(['run' => 'nope', 'outcome' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['run', 'outcome']);
});

it('keeps working under a renamed slug', function (): void {
    $oldSlug = $this->organisation->slug;
    Tenancy::run($this->organisation, fn () => app(RenameOrganisationSlug::class)->handle($this->owner, $this->organisation, 'acme-group'));

    claimOverHttp(slug: $oldSlug)->assertOk();
});

it('slows down a token that polls too fast', function (): void {
    foreach (range(1, 30) as $ignored) {
        claimOverHttp()->assertOk();
    }

    claimOverHttp()->assertTooManyRequests();
});
