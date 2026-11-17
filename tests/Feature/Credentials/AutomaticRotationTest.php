<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\RemoveAutomaticRotation;
use App\Modules\Credentials\Actions\RequestRotation;
use App\Modules\Credentials\Actions\RevokeRunner;
use App\Modules\Credentials\Actions\SetAutomaticRotation;
use App\Modules\Credentials\Enums\RotationSource;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\AutomaticRotation;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    onTeamPlan($this->organisation);
    $this->runner = runnerFor($this->organisation, $this->owner)['runner'];
    $this->credential = Tenancy::run($this->organisation, fn (): Credential => Credential::factory()->create([
        'application_id' => Application::factory()->create()->id,
    ]));
});

/**
 * @param  array<string, mixed>  $data
 */
function setAutomaticRotation(array $data, ?User $actor = null, ?Credential $credential = null): AutomaticRotation
{
    return Tenancy::run(test()->organisation, fn (): AutomaticRotation => app(SetAutomaticRotation::class)->handle($actor ?? test()->owner, ($credential ?? test()->credential)->refresh(), $data));
}

/**
 * The validation errors setting these would give, by field.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function automaticRotationErrors(array $data, ?Credential $credential = null): array
{
    try {
        setAutomaticRotation($data, credential: $credential);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('switches on automatic rotation for a credential', function (): void {
    $automaticRotation = setAutomaticRotation(automaticRotationData($this->runner));

    expect($automaticRotation->source)->toBe(RotationSource::OpenAi)
        ->and($automaticRotation->runner_id)->toBe($this->runner->id)
        ->and($automaticRotation->source_settings)->toBe(['project_id' => 'proj_abc', 'service_account_id' => 'svc_acct_abc', 'key_lifetime_days' => null])
        ->and($automaticRotation->destinations)->toBe([
            ['type' => 'laravel_cloud', 'secret_id' => 'sec-1', 'secret_name' => 'OPENAI_API_KEY', 'environment_ids' => ['env-1'], 'redeploy' => true],
        ]);
});

it('replaces the settings when set again', function (): void {
    setAutomaticRotation(automaticRotationData($this->runner));
    setAutomaticRotation(automaticRotationData($this->runner, ['source_settings.project_id' => 'proj_new']));

    expect(Tenancy::run($this->organisation, fn () => AutomaticRotation::query()->pluck('source_settings')->all()))
        ->toHaveCount(1)
        ->and(Tenancy::run($this->organisation, fn (): string => AutomaticRotation::query()->sole()->source_settings['project_id']))->toBe('proj_new');
});

it('refuses a revoked runner and a runner from another organisation', function (): void {
    $revoked = runnerFor($this->organisation, $this->owner, 'Old laptop')['runner'];
    Tenancy::run($this->organisation, fn () => app(RevokeRunner::class)->handle($this->owner, $revoked));
    $otherOwner = User::factory()->create();
    $other = app(CreateOrganisation::class)->handle($otherOwner, 'Globex');
    onTeamPlan($other);
    $foreign = runnerFor($other, $otherOwner)['runner'];

    expect(automaticRotationErrors(automaticRotationData($revoked)))->toHaveKey('runner_id')
        ->and(automaticRotationErrors(automaticRotationData($foreign)))->toHaveKey('runner_id');
});

it('refuses anything that looks like a key', function (string $field): void {
    $key = 'sk-proj-'.str_repeat('a1B2', 10);

    expect(automaticRotationErrors(automaticRotationData($this->runner, [$field => $key])))->toHaveKey($field)
        ->and(Tenancy::run($this->organisation, fn (): int => AutomaticRotation::query()->count()))->toBe(0);
})->with(['source_settings.project_id', 'source_settings.service_account_id', 'destinations.0.secret_id', 'destinations.0.environment_ids.0']);

it('drops settings it does not know', function (): void {
    $automaticRotation = setAutomaticRotation(automaticRotationData($this->runner, [
        'source_settings.api_key' => 'x', 'destinations.0.token' => 'y',
    ]));

    expect($automaticRotation->source_settings)->not->toHaveKey('api_key')
        ->and($automaticRotation->destinations[0])->not->toHaveKey('token');
});

it('asks for the environments to redeploy', function (): void {
    expect(automaticRotationErrors(automaticRotationData($this->runner, ['destinations.0.environment_ids' => []])))
        ->toHaveKey('destinations.0.environment_ids');
});

it('needs a rotation schedule and a credential that is not revoked', function (array $attributes, string $message): void {
    $credential = Tenancy::run($this->organisation, fn (): Credential => Credential::factory()->create([
        'application_id' => Application::factory()->create()->id, ...$attributes,
    ]));

    expect(automaticRotationErrors(automaticRotationData($this->runner), $credential)['runner_id'] ?? [])->toContain($message);
})->with([
    'no schedule' => [['rotation_days' => null], 'Give this credential a rotation schedule first.'],
    'revoked' => [['revoked_at' => now()], 'A revoked credential can\'t be rotated.'],
]);

it('asks for a key lifetime when the credential expires', function (): void {
    Tenancy::run($this->organisation, fn () => $this->credential->forceFill(['expires_at' => now()->addMonths(6)])->save());

    expect(automaticRotationErrors(automaticRotationData($this->runner))['source_settings.key_lifetime_days'] ?? [])
        ->toContain('This credential expires: say how many days each new key lasts.');
});

it('is part of the Team plan', function (): void {
    $owner = User::factory()->create();
    $free = app(CreateOrganisation::class)->handle($owner, 'Globex');
    $credential = Tenancy::run($free, fn (): Credential => Credential::factory()->create(['application_id' => Application::factory()->create()->id]));

    expect(fn () => Tenancy::run($free, fn () => app(SetAutomaticRotation::class)->handle($owner, $credential, automaticRotationData($this->runner))))
        ->toThrow(ValidationException::class, 'Automatic rotation is part of the Team plan.');
});

it('needs credentials.manage to set it up', function (): void {
    setAutomaticRotation(automaticRotationData($this->runner), memberWithRole($this->organisation, SystemRole::Viewer));
})->throws(AuthorizationException::class);

it('lets the credential\'s owner ask for a rotation now, and nobody else without the right', function (): void {
    setAutomaticRotation(automaticRotationData($this->runner));
    $owner = memberWithRole($this->organisation, SystemRole::Viewer);
    Tenancy::run($this->organisation, fn () => $this->credential->forceFill([
        'owner_id' => Person::factory()->create(['user_id' => $owner->id, 'email' => $owner->email])->id,
    ])->save());

    $requested = Tenancy::run($this->organisation, fn (): AutomaticRotation => app(RequestRotation::class)->handle($owner, $this->credential->refresh()));

    expect($requested->requested_at)->not->toBeNull()
        ->and(fn () => Tenancy::run($this->organisation, fn () => app(RequestRotation::class)->handle(memberWithRole($this->organisation, SystemRole::Viewer), $this->credential)))
        ->toThrow(AuthorizationException::class);
});

it('can\'t ask for a rotation without automatic rotation', function (): void {
    Tenancy::run($this->organisation, fn () => app(RequestRotation::class)->handle($this->owner, $this->credential));
})->throws(ValidationException::class, 'Set up automatic rotation for this credential first.');

it('switches automatic rotation off and keeps the credential', function (): void {
    setAutomaticRotation(automaticRotationData($this->runner));

    Tenancy::run($this->organisation, fn () => app(RemoveAutomaticRotation::class)->handle($this->owner, $this->credential));

    expect(Tenancy::run($this->organisation, fn (): int => AutomaticRotation::query()->count()))->toBe(0)
        ->and(Tenancy::run($this->organisation, fn (): bool => Credential::query()->whereKey($this->credential->id)->exists()))->toBeTrue();
});
