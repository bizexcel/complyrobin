<?php

use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\ClaimRotationRuns;
use App\Modules\Credentials\Actions\RemoveAutomaticRotation;
use App\Modules\Credentials\Actions\RequestRotation;
use App\Modules\Credentials\Actions\RevokeRunner;
use App\Modules\Credentials\Enums\RotationRunStatus;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\RotationRun;
use App\Modules\Credentials\Models\Runner;
use App\Modules\Credentials\Notifications\AutomaticRotationFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
    ['runner' => $this->runner, 'token' => $this->token] = runnerFor($this->organisation, $this->owner);
});

/**
 * @return array{jobs: list<array<string, mixed>>}|null
 */
function claimRuns(?string $token = null, ?Organisation $organisation = null): ?array
{
    return app(ClaimRotationRuns::class)->handle($organisation ?? test()->organisation, $token ?? test()->token);
}

/**
 * @return list<string> the references of the credentials handed out
 */
function claimedReferences(?string $token = null): array
{
    return array_map(fn (array $job): string => $job['credential']['reference'], claimRuns($token)['jobs'] ?? []);
}

function runOf(Credential $credential): RotationRun
{
    return Tenancy::run(test()->organisation, fn (): RotationRun => RotationRun::query()->where('credential_id', $credential->id)->latest('claimed_at')->firstOrFail());
}

it('hands a due credential to its runner in the agreed shape', function (): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);

    $claim = claimRuns();
    $run = runOf($credential);

    expect($claim)->toEqual(['jobs' => [[
        'run' => $run->id,
        'credential' => ['reference' => $credential->reference, 'name' => 'OpenAI production key'],
        'source' => ['type' => 'openai', 'project_id' => 'proj_abc', 'service_account_id' => 'svc_acct_abc', 'key_lifetime_days' => null],
        'destinations' => [
            ['type' => 'laravel_cloud', 'secret_id' => 'sec-1', 'secret_name' => 'OPENAI_API_KEY', 'environment_ids' => ['env-1'], 'redeploy' => true],
        ],
        'grace_hours' => 48,
    ]]])
        ->and($run->status)->toBe(RotationRunStatus::Claimed)
        ->and($this->runner->refresh()->last_seen_at)->not->toBeNull();
});

it('hands out a credential due today, but nothing that is not due yet', function (): void {
    $today = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: 0);
    automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: 3);

    expect(claimedReferences())->toBe([$today->reference]);
});

it('hands out a credential someone asked to rotate now, once', function (): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: 30);
    Tenancy::run($this->organisation, fn () => app(RequestRotation::class)->handle($this->owner, $credential));

    expect(claimedReferences())->toBe([$credential->reference]);

    Tenancy::run($this->organisation, fn () => runOf($credential)->forceFill(['status' => RotationRunStatus::Succeeded, 'finished_at' => now()])->save());

    expect(claimedReferences())->toBe([])
        ->and(Tenancy::run($this->organisation, fn () => $credential->automaticRotation()->sole()->requested_at))->toBeNull();
});

it('never hands out the same credential twice while a run is open', function (): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -2);

    expect(claimedReferences())->toBe([$credential->reference])
        ->and(claimedReferences())->toBe([]);
});

it('gives up on a run after an hour, tells the owner, and retries after six hours', function (): void {
    $person = Tenancy::run($this->organisation, fn (): Person => Person::factory()->create(['user_id' => $this->owner->id, 'email' => $this->owner->email]));
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1, attributes: ['owner_id' => $person->id]);
    claimRuns();

    $this->travel(61)->minutes();

    expect(claimedReferences())->toBe([])
        ->and(runOf($credential)->status)->toBe(RotationRunStatus::Abandoned)
        ->and(runOf($credential)->failure)->toBe('The runner did not report back.');
    Notification::assertSentTo($this->owner, AutomaticRotationFailed::class, fn (AutomaticRotationFailed $notification): bool => $notification->credentialReference === $credential->reference);

    $this->travel(5)->hours();
    expect(claimedReferences())->toBe([]);

    $this->travel(2)->hours();
    expect(claimedReferences())->toBe([$credential->reference]);
});

it('tells the owners and admins when the credential has nobody who can sign in', function (): void {
    automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
    claimRuns();
    $this->travel(61)->minutes();

    claimRuns();

    Notification::assertSentTo($this->owner, AutomaticRotationFailed::class);
});

it('only hands a runner its own credentials', function (): void {
    automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
    ['token' => $otherToken] = runnerFor($this->organisation, $this->owner, 'Laravel app');

    expect(claimedReferences($otherToken))->toBe([]);
});

it('hands out nothing once the organisation is back on Free', function (): void {
    $owner = User::factory()->create();
    $organisation = app(CreateOrganisation::class)->handle($owner, 'Globex');
    onTeamPlan($organisation, now()->addHour());
    ['runner' => $runner, 'token' => $token] = runnerFor($organisation, $owner);
    automatedCredential($organisation, $owner, $runner, dueInDays: -1);

    $this->travel(2)->hours();

    expect(claimRuns($token, $organisation))->toBe(['jobs' => []]);
});

it('hands out nothing for a revoked or deleted credential', function (string $change): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
    Tenancy::run($this->organisation, fn () => $change === 'revoked' ? $credential->forceFill(['revoked_at' => now()])->save() : $credential->delete());

    expect(claimedReferences())->toBe([]);
})->with(['revoked', 'deleted']);

it('hands out at most five jobs at a time, the longest overdue first', function (): void {
    $credentials = collect(range(1, 6))->map(fn (int $days): Credential => automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -$days));

    expect(claimedReferences())->toBe($credentials->reverse()->take(5)->pluck('reference')->values()->all())
        ->and(claimedReferences())->toBe([$credentials->first()->reference]);
});

it('answers null for an unknown, revoked or foreign token', function (): void {
    $otherOwner = User::factory()->create();
    $other = app(CreateOrganisation::class)->handle($otherOwner, 'Globex');
    onTeamPlan($other);
    ['token' => $foreignToken] = runnerFor($other, $otherOwner);

    expect(claimRuns('crr_'.str_repeat('a', 60)))->toBeNull()
        ->and(claimRuns($foreignToken))->toBeNull();

    Tenancy::run($this->organisation, fn (): Runner => app(RevokeRunner::class)->handle($this->owner, $this->runner));

    expect(claimRuns())->toBeNull();
});

it('refuses to switch automatic rotation off while a run is open', function (): void {
    $credential = automatedCredential($this->organisation, $this->owner, $this->runner, dueInDays: -1);
    claimRuns();

    Tenancy::run($this->organisation, fn () => app(RemoveAutomaticRotation::class)->handle($this->owner, $credential));
})->throws(ValidationException::class, 'This credential is being rotated right now. Try again in an hour.');
