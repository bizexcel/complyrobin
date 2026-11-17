<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\CreateRunner;
use App\Modules\Credentials\Actions\RevokeRunner;
use App\Modules\Credentials\Models\Runner;
use App\Modules\Credentials\Support\RunnerTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
});

it('creates a runner and shows its token once, storing only a hash', function (): void {
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation, $this->owner);

    expect($token)->toMatch('/^crr_[A-Za-z0-9]{60}$/')
        ->and($runner->token)->toBe(hash('sha256', $token))
        ->and($runner->toArray())->not->toHaveKey('token')
        ->and(Tenancy::run($this->organisation, fn (): ?string => RunnerTokens::find($token)?->id))->toBe($runner->id);
});

it('never writes the token to the audit trail', function (): void {
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation, $this->owner);

    $logged = DB::connection('pgsql_migrator')->table('activities')->where('subject_id', $runner->id)->pluck('properties')->implode(' ');

    expect($logged)->toContain('GitHub')
        ->not->toContain($token)
        ->not->toContain(hash('sha256', $token));
});

it('needs credentials.manage', function (): void {
    runnerFor($this->organisation, memberWithRole($this->organisation, SystemRole::Viewer));
})->throws(AuthorizationException::class);

it('is part of the Team plan', function (): void {
    $owner = User::factory()->create();
    $free = app(CreateOrganisation::class)->handle($owner, 'Globex');

    expect(fn () => runnerFor($free, $owner))->toThrow(ValidationException::class, 'Automatic rotation is part of the Team plan.');
});

it('refuses a second runner with the same name', function (): void {
    runnerFor($this->organisation, $this->owner);

    expect(fn () => runnerFor($this->organisation, $this->owner))->toThrow(ValidationException::class, 'You already have a runner with this name.');
});

it('stops finding a revoked runner, one from another organisation, or a malformed token', function (): void {
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation, $this->owner);
    $otherOwner = User::factory()->create();
    $other = app(CreateOrganisation::class)->handle($otherOwner, 'Globex');

    expect(Tenancy::run($other, fn (): ?Runner => RunnerTokens::find($token)))->toBeNull()
        ->and(Tenancy::run($this->organisation, fn (): ?Runner => RunnerTokens::find('crr_short')))->toBeNull()
        ->and(Tenancy::run($this->organisation, fn (): ?Runner => RunnerTokens::find('')))->toBeNull();

    Tenancy::run($this->organisation, fn (): Runner => app(RevokeRunner::class)->handle($this->owner, $runner));

    expect(Tenancy::run($this->organisation, fn (): ?Runner => RunnerTokens::find($token)))->toBeNull()
        ->and($runner->refresh()->revoked_at)->not->toBeNull();
});

it('lets only members who manage credentials revoke a runner', function (): void {
    ['runner' => $runner] = runnerFor($this->organisation, $this->owner);
    $viewer = memberWithRole($this->organisation, SystemRole::Viewer);

    Tenancy::run($this->organisation, fn (): Runner => app(RevokeRunner::class)->handle($viewer, $runner));
})->throws(AuthorizationException::class);
