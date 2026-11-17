<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\CreateCredential;
use App\Modules\Credentials\Actions\DeleteApplication;
use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Actions\UpdateCredential;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    $this->application = Tenancy::run($this->organisation, fn () => Application::factory()->create(['name' => 'Laravel Cloud']));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function addCredential(User $actor, array $overrides = []): Credential
{
    return Tenancy::run(test()->organisation, fn (): Credential => app(CreateCredential::class)->handle($actor, [
        'application_id' => test()->application->id, 'name' => 'Production deploy token', 'type' => 'api_token',
        'environment' => 'production', 'rotation_days' => 90, ...$overrides,
    ]));
}

function credentialsIn(Organisation $organisation, int $count): void
{
    Tenancy::run($organisation, fn () => Credential::factory()->count($count)->create(['application_id' => test()->application->id]));
}

function grantTeam(Organisation $organisation, ?Carbon $endsAt = null): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $organisation, Module::Credentials, now()->subDays(30), $endsAt);
}

it('adds a credential with the next reference', function (): void {
    expect([addCredential($this->owner)->reference, addCredential($this->owner)->reference])->toBe(['CRD-0001', 'CRD-0002']);
});

it('works out the due date from the interval, the last rotation and the vendor\'s expiry', function (array $dates, string $due): void {
    $this->travelTo(Carbon::parse('2026-01-10 08:00', 'UTC'));

    $credential = addCredential($this->owner, $dates);

    expect($credential->next_rotation_at->toIso8601String())->toBe(Carbon::parse($due, 'UTC')->toIso8601String());
})->with([
    'interval from the last rotation' => [['rotation_days' => 90, 'last_rotated_at' => '2026-01-01 10:00:00'], '2026-04-01 10:00'],
    'interval from today if never rotated' => [['rotation_days' => 30], '2026-02-09 08:00'],
    'an expiry sooner than the interval' => [['rotation_days' => 90, 'expires_at' => '2026-02-01 00:00:00'], '2026-02-01 00:00'],
    'an expiry only' => [['rotation_days' => null, 'expires_at' => '2026-06-01 00:00:00'], '2026-06-01 00:00'],
]);

it('keeps the due date for the database to work out', function (): void {
    $credential = addCredential($this->owner);

    Tenancy::run($this->organisation, fn () => DB::table('credentials')->where('id', $credential->id)->update(['next_rotation_at' => now()->addYears(5)]));
})->throws(QueryException::class, 'generated');

it('stops at 5 credentials on Free and says so', function (): void {
    credentialsIn($this->organisation, 5);

    try {
        addCredential($this->owner);
        $this->fail('Added a sixth credential on Free.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['name'][0])->toBe('Your plan allows 5 credentials. Revoke one you no longer use, or upgrade for more.');
    }
});

it('stops at 100 credentials on Pro', function (): void {
    onProPlan($this->organisation);
    credentialsIn($this->organisation, 100);

    expect(fn (): Credential => addCredential($this->owner))->toThrow(ValidationException::class, '100 credentials');
});

it('doesn\'t count revoked or deleted credentials towards the limit', function (string $howItWasRetired): void {
    credentialsIn($this->organisation, 5);
    Tenancy::run($this->organisation, function () use ($howItWasRetired): void {
        $retired = Credential::query()->first();
        $howItWasRetired === 'revoked' ? app(RevokeCredential::class)->handle($this->owner, $retired) : $retired->delete();
    });

    expect(addCredential($this->owner)->reference)->toStartWith('CRD-');
})->with(['revoked', 'deleted']);

it('has no credential limit on Team', function (): void {
    grantTeam($this->organisation);
    credentialsIn($this->organisation, 10);

    expect(addCredential($this->owner)->reference)->toStartWith('CRD-');
});

it('keeps every credential editable and revocable after a downgrade, but adds no more', function (): void {
    grantTeam($this->organisation, now()->addMinute());
    credentialsIn($this->organisation, 12);
    $this->travel(2)->minutes();
    app(Entitlements::class)->forget($this->organisation);
    $existing = Tenancy::run($this->organisation, fn () => Credential::query()->first());

    Tenancy::run($this->organisation, function () use ($existing): void {
        app(UpdateCredential::class)->handle($this->owner, $existing, [
            'application_id' => $this->application->id, 'name' => 'Renamed', 'type' => 'api_token', 'environment' => 'production', 'rotation_days' => 60,
        ]);
        app(RevokeCredential::class)->handle($this->owner, $existing);
    });

    expect(fn (): Credential => addCredential($this->owner))->toThrow(ValidationException::class);
});

it('refuses an application or owner from another organisation', function (string $field): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn (): array => ['application_id' => Application::factory()->create()->id, 'owner_id' => Person::factory()->create()->id]);

    try {
        addCredential($this->owner, [$field => $theirs[$field]]);
        $this->fail("Accepted another organisation's {$field}.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with(['application_id', 'owner_id']);

it('refuses another organisation\'s application in the database, too', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $theirs = Tenancy::run($globex, fn () => Application::factory()->create());

    DB::connection('pgsql_migrator')->table('credentials')->insert([
        'id' => (string) Str::uuid7(), 'organisation_id' => $this->organisation->id, 'reference' => 'CRD-9999',
        'application_id' => $theirs->id, 'name' => 'Sneaky', 'type' => 'api_token', 'environment' => 'production', 'rotation_days' => 90,
        'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'foreign key');

it('lets only members who manage credentials add them', function (SystemRole $role): void {
    addCredential(memberWithRole($this->organisation, $role));
})->with([SystemRole::Viewer, SystemRole::Approver])->throws(AuthorizationException::class);

it('refuses anything that looks like the secret itself', function (string $field, string $value): void {
    try {
        addCredential($this->owner, [$field => $value]);
        $this->fail("Accepted [{$value}] as the {$field}.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'a GitHub token as the name' => ['name', 'ghp_'.str_repeat('a1B2', 9)],
    'an AWS key id in where it\'s used' => ['used_in', 'prod uses AKIAIOSFODNN7EXAMPLE'],
    'a Stripe key in where it\'s used' => ['used_in', 'sk_live_51HxYzAbCdEfGhIjKlMn'],
    'a private key' => ['used_in', "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA"],
    'a JWT' => ['used_in', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signature'],
    'a long random string' => ['used_in', 'aB3dE5gH7jK9mN1pQ3sT5vX7zA9cE1gI3kM5oQ7s'],
    'a whole key as the hint' => ['hint', 'ghp_aB3dE5gH7'],
]);

it('accepts where a credential is used, written in plain words', function (): void {
    $credential = addCredential($this->owner, ['used_in' => 'Laravel Cloud production: STRIPE_SECRET, https://cloud.laravel.com/acme/env/production', 'hint' => '…a9f2']);

    expect($credential->hint)->toBe('…a9f2');
});

it('can\'t store more than 8 characters of hint, even directly in the database', function (): void {
    $credential = addCredential($this->owner);

    DB::connection('pgsql_migrator')->table('credentials')->where('id', $credential->id)->update(['hint' => 'abcdefghi']);
})->throws(QueryException::class, 'too long');

it('works out the status on the organisation\'s own day', function (string $now, CredentialStatus $status): void {
    $this->travelTo(Carbon::parse('2026-03-01 00:00', 'UTC'));
    $credential = addCredential($this->owner, ['rotation_days' => null, 'expires_at' => '2026-06-30 23:59:00', 'last_rotated_at' => null]);
    Tenancy::run($this->organisation, fn () => DB::connection('pgsql_migrator')->table('credentials')->where('id', $credential->id)
        ->update(['expires_at' => null, 'rotation_days' => 31, 'last_rotated_at' => '2026-02-28 18:00:00+00']));
    // Due 2026-03-31 18:00 UTC, which is 1 April 02:00 in Kuala Lumpur.

    $this->travelTo(Carbon::parse($now, 'UTC'));

    expect(Tenancy::run($this->organisation, fn () => $credential->refresh()->status()))->toBe($status);
})->with([
    'the day before, in Kuala Lumpur' => ['2026-03-31 15:00', CredentialStatus::DueSoon],
    'due today in Kuala Lumpur, still 31 March in UTC' => ['2026-03-31 19:00', CredentialStatus::DueSoon],
    'the day after, in Kuala Lumpur' => ['2026-04-01 16:30', CredentialStatus::Overdue],
]);

it('won\'t change a revoked credential, even for an Owner', function (): void {
    $credential = addCredential($this->owner);
    Tenancy::run($this->organisation, fn () => app(RevokeCredential::class)->handle($this->owner, $credential));

    Tenancy::run($this->organisation, fn () => app(UpdateCredential::class)->handle($this->owner, $credential, [
        'application_id' => $this->application->id, 'name' => 'Back again', 'type' => 'api_token', 'environment' => 'production', 'rotation_days' => 90,
    ]));
})->throws(ValidationException::class, 'revoked');

it('won\'t delete an application that still has active credentials', function (): void {
    addCredential($this->owner);

    Tenancy::run($this->organisation, fn () => app(DeleteApplication::class)->handle($this->owner, $this->application));
})->throws(ValidationException::class);
