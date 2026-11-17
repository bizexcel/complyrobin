<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\ClaimRotationRuns;
use App\Modules\Credentials\Actions\ReportRotationRun;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Notifications\AutomaticRotationFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
    ['runner' => $this->runner, 'token' => $this->token] = runnerFor($this->organisation, $this->owner);
    $this->credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
});

function claimedRun(): RotationRun
{
    $claim = app(ClaimRotationRuns::class)->handle(test()->organisation, test()->token);

    return Tenancy::run(test()->organisation, fn (): RotationRun => RotationRun::query()->findOrFail($claim['jobs'][0]['run']));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function successReport(RotationRun $run, array $overrides = []): array
{
    $report = [
        'run' => $run->id, 'outcome' => 'succeeded', 'rotated_at' => now()->toIso8601String(),
        'new_key' => ['id' => 'key_abc', 'hint' => 'AB12', 'expires_at' => null],
        'old_keys_expire_at' => now()->addHours(48)->toIso8601String(), 'deployments' => ['dep-1'],
    ];

    foreach ($overrides as $key => $value) {
        data_set($report, $key, $value);
    }

    return $report;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function failureReport(RotationRun $run, array $overrides = []): array
{
    return ['run' => $run->id, 'outcome' => 'failed', 'step' => 'push', 'message' => 'Laravel Cloud answered 422.', ...$overrides];
}

/**
 * @param  array<string, mixed>  $report
 * @return array{status: int, body: array<string, mixed>}|null
 */
function reportRun(array $report, ?string $token = null, ?Organisation $organisation = null): ?array
{
    return app(ReportRotationRun::class)->handle($organisation ?? test()->organisation, $token ?? test()->token, $report);
}

function reloadedRun(RotationRun $run): RotationRun
{
    return Tenancy::run(test()->organisation, fn (): RotationRun => $run->refresh());
}

function rotationsOf(Credential $credential): int
{
    return Tenancy::run(test()->organisation, fn (): int => CredentialRotation::query()->where('credential_id', $credential->id)->count());
}

it('records a rotation from a successful report', function (): void {
    $run = claimedRun();
    $wasDue = $this->credential->next_rotation_at;

    expect(reportRun(successReport($run)))->toBe(['status' => 200, 'body' => ['recorded' => true]]);

    $rotation = Tenancy::run($this->organisation, fn (): CredentialRotation => CredentialRotation::query()->where('credential_id', $this->credential->id)->sole());
    $credential = Tenancy::run($this->organisation, fn (): Credential => $this->credential->refresh());
    reloadedRun($run);

    expect($rotation->rotated_by_id)->toBeNull()
        ->and($rotation->due_at->equalTo($wasDue))->toBeTrue()
        ->and($rotation->notes)->toBe('Rotated automatically by the runner "GitHub".')
        ->and($credential->last_rotated_at->equalTo($rotation->rotated_at))->toBeTrue()
        ->and($credential->next_rotation_at->equalTo($rotation->rotated_at->copy()->addDays(90)))->toBeTrue()
        ->and($credential->hint)->toBe('AB12')
        ->and($run->status)->toBe(RotationRunStatus::Succeeded)
        ->and($run->new_key_identifier)->toBe('key_abc')
        ->and($run->deployments)->toBe(['dep-1'])
        ->and($run->credential_rotation_id)->toBe($rotation->id);
});

it('refuses a report that carries something that looks like a key, and keeps it nowhere', function (string $field, bool $isFailure): void {
    $key = 'sk-proj-'.str_repeat('a1B2', 10);
    $run = claimedRun();
    $report = $isFailure ? failureReport($run, [$field => $key]) : successReport($run, [$field => $key]);

    expect(fn (): ?array => reportRun($report))->toThrow(ValidationException::class);

    expect(reloadedRun($run)->status)->toBe(RotationRunStatus::Claimed)
        ->and(rotationsOf($this->credential))->toBe(0)
        ->and(DB::connection('pgsql_migrator')->table('activities')->whereRaw('properties::text like ? or attribute_changes::text like ?', ["%{$key}%", "%{$key}%"])->count())->toBe(0);
})->with([
    'as the new key id' => ['new_key.id', false],
    'as the hint' => ['new_key.hint', false],
    'as a deployment' => ['deployments.0', false],
    'in a failure message' => ['message', true],
]);

it('records nothing new when the same report arrives twice', function (): void {
    $run = claimedRun();
    $report = successReport($run);

    reportRun($report);

    expect(reportRun($report))->toBe(['status' => 200, 'body' => ['recorded' => true]])
        ->and(rotationsOf($this->credential))->toBe(1);
});

it('accepts a late success for a run it had given up on', function (): void {
    $run = claimedRun();
    $rotatedAt = now()->addMinutes(10);
    $this->travel(61)->minutes();
    app(ClaimRotationRuns::class)->handle($this->organisation, $this->token);

    expect(reloadedRun($run)->status)->toBe(RotationRunStatus::Abandoned)
        ->and(reportRun(successReport($run, ['rotated_at' => $rotatedAt->toIso8601String()]))['status'])->toBe(200)
        ->and(reloadedRun($run)->status)->toBe(RotationRunStatus::Succeeded)
        ->and(rotationsOf($this->credential))->toBe(1);
});

it('answers 409 when a finished run is reported with the other outcome', function (): void {
    $run = claimedRun();
    reportRun(successReport($run));

    expect(reportRun(failureReport($run)))->toBe(['status' => 409, 'body' => ['message' => 'This run has already been reported.']]);
});

it('stores why a run failed and tells the owners, leaving the credential as it was', function (): void {
    $run = claimedRun();
    $lastRotated = $this->credential->last_rotated_at;

    expect(reportRun(failureReport($run))['status'])->toBe(200);

    reloadedRun($run);
    expect($run->status)->toBe(RotationRunStatus::Failed)
        ->and($run->failed_step)->toBe('push')
        ->and($run->failure)->toBe('Laravel Cloud answered 422.')
        ->and(Tenancy::run($this->organisation, fn () => $this->credential->refresh()->last_rotated_at->equalTo($lastRotated)))->toBeTrue();
    Notification::assertSentTo($this->owner, AutomaticRotationFailed::class, fn (AutomaticRotationFailed $notification): bool => str_contains($notification->body(), 'Laravel Cloud answered 422.'));
});

it('refuses a rotation time before the claim or in the future', function (string $when): void {
    $run = claimedRun();
    $rotatedAt = $when === 'before' ? now()->subMinutes(10) : now()->addMinutes(10);

    expect(fn (): ?array => reportRun(successReport($run, ['rotated_at' => $rotatedAt->toIso8601String()])))->toThrow(ValidationException::class, 'The rotation time must be between when the run was claimed and now.');
    expect(rotationsOf($this->credential))->toBe(0);
})->with(['before', 'after']);

it('refuses a success for a credential revoked meanwhile, and marks the run failed', function (): void {
    $run = claimedRun();
    Tenancy::run($this->organisation, fn () => $this->credential->forceFill(['revoked_at' => now()])->save());

    expect(fn (): ?array => reportRun(successReport($run)))->toThrow(ValidationException::class);
    expect(reloadedRun($run)->status)->toBe(RotationRunStatus::Failed)
        ->and(rotationsOf($this->credential))->toBe(0);
});

it('asks for the new key\'s expiry when the credential expires', function (): void {
    Tenancy::run($this->organisation, fn () => $this->credential->forceFill(['expires_at' => now()->subHour()])->save());
    $run = claimedRun();

    expect(fn (): ?array => reportRun(successReport($run)))->toThrow(ValidationException::class, 'This credential expires: send the new key\'s expiry date.');
});

it('answers 404 for another runner\'s run and null for a bad token', function (): void {
    $run = claimedRun();
    ['token' => $otherToken] = runnerFor($this->organisation, $this->owner, 'Laravel app');

    expect(reportRun(successReport($run), $otherToken)['status'])->toBe(404)
        ->and(reportRun(successReport($run), 'crr_'.str_repeat('b', 60)))->toBeNull();
});

it('answers 403 once the organisation is back on Free', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Globex');
    onTeamPlan($organisation, now()->addHour());
    ['runner' => $runner, 'token' => $token] = runnerFor($organisation, $owner);
    automatedCredential($organisation, $owner, $runner, dueInDays: -1);
    $claim = app(ClaimRotationRuns::class)->handle($organisation, $token);

    $this->travel(2)->hours();

    expect(reportRun(['run' => $claim['jobs'][0]['run'], 'outcome' => 'failed', 'step' => 'mint', 'message' => 'x'], $token, $organisation)['status'])->toBe(403);
});
