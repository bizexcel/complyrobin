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
use App\Filament\App\Resources\Credentials\Pages\ListCredentials;
use App\Modules\Credentials\Actions\StartCredentialImport;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Notifications\CredentialsImported;
use App\Modules\Credentials\Support\CredentialSpreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

const IMPORT_HEADER = "name,application,type,environment,owner_email,backup_owner_email,rotation_days,last_rotated_on,expires_on,used_in,hint\n";

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    [$this->stripe, $this->siti] = Tenancy::run($this->organisation, fn (): array => [
        Application::factory()->create(['name' => 'Stripe']),
        Person::factory()->create(['name' => 'Siti', 'email' => 'siti@acme.test']),
    ]);
});

function importTeamPlan(Organisation $organisation, ?Carbon $endsAt = null): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $organisation, Module::Credentials, now()->subDay(), $endsAt);
}

function importSpreadsheet(string $rows, ?User $actor = null): int
{
    return Tenancy::run(test()->organisation, fn (): int => app(StartCredentialImport::class)->handle($actor ?? test()->owner, IMPORT_HEADER.$rows));
}

function importedCredentials(): array
{
    return Tenancy::run(test()->organisation, fn (): array => Credential::query()->orderBy('reference')->get()->all());
}

it('imports each row as a new credential, matching applications by name and owners by email', function (): void {
    importTeamPlan($this->organisation);

    $rows = importSpreadsheet(
        "Stripe live key,stripe,api_token,production,SITI@acme.test,,90,2026-01-15,,\"Laravel Cloud prod: STRIPE_SECRET\nand the worker\",sk_l\n"
        ."Stripe webhook secret,Stripe,webhook_secret,staging,,,30,,,,\n",
    );

    $credentials = importedCredentials();
    expect($rows)->toBe(2)
        ->and($credentials)->toHaveCount(2)
        ->and($credentials[0])->reference->toStartWith('CRD-')->name->toBe('Stripe live key')
        ->application_id->toBe($this->stripe->id)->owner_id->toBe($this->siti->id)->hint->toBe('sk_l')
        ->and($credentials[0]->used_in)->toBe("Laravel Cloud prod: STRIPE_SECRET\nand the worker")
        ->and($credentials[1])->owner_id->toBeNull()->rotation_days->toBe(30);
    Notification::assertSentTo($this->owner, CredentialsImported::class, fn (CredentialsImported $notification): bool => $notification->imported === 2 && $notification->failures === []);
});

it('skips rows that fail, saying which and why, and imports the rest', function (): void {
    importTeamPlan($this->organisation);

    importSpreadsheet(
        "Good key,Stripe,api_token,production,,,90,,,,\n"
        ."Leaky key,Stripe,api_token,production,,,90,,,,ghp_16C7e42F292c6912E7710c838347Ae178B4a\n"
        ."Unknown app key,Mailgun,api_token,production,,,90,,,,\n"
        ."Bad owner key,Stripe,api_token,production,nobody@acme.test,,90,,,,\n"
        ."Bad type key,Stripe,magic_token,production,,,90,,,,\n",
    );

    expect(array_map(fn (Credential $credential): string => $credential->name, importedCredentials()))->toBe(['Good key']);
    Notification::assertSentTo($this->owner, CredentialsImported::class, function (CredentialsImported $notification): bool {
        expect($notification->imported)->toBe(1)
            ->and(array_keys($notification->failures))->toBe([3, 4, 5, 6])
            ->and($notification->failures[4])->toContain('Mailgun')
            ->and($notification->failures[5])->toContain('nobody@acme.test');

        return true;
    });
});

it('never matches another organisation\'s applications or people', function (): void {
    importTeamPlan($this->organisation);
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    Tenancy::run($globex, function (): void {
        Application::factory()->create(['name' => 'Payroll']);
        Person::factory()->create(['email' => 'boss@globex.test']);
    });

    importSpreadsheet("Payroll key,Payroll,api_token,production,,,90,,,,\nStripe key,Stripe,api_token,production,boss@globex.test,,90,,,,\n");

    expect(importedCredentials())->toBe([]);
});

it('is a Team feature, offered only to members who manage credentials', function (): void {
    openOrganisationAs($this->owner, $this->organisation);
    Livewire::test(ListCredentials::class)->assertActionHidden('import');
    expect(fn (): int => importSpreadsheet("Key,Stripe,api_token,production,,,90,,,,\n"))->toThrow(ValidationException::class, 'part of Team');

    importTeamPlan($this->organisation);
    app(Entitlements::class)->forget($this->organisation);
    Livewire::test(ListCredentials::class)->assertActionVisible('import');

    $viewer = memberWithRole($this->organisation, SystemRole::Viewer);
    openOrganisationAs($viewer, $this->organisation);
    Livewire::test(ListCredentials::class)->assertActionHidden('import');
    expect(fn (): int => importSpreadsheet("Key,Stripe,api_token,production,,,90,,,,\n", $viewer))->toThrow(AuthorizationException::class);
});

it('refuses a file that is too big, empty, too long or missing columns', function (string $csv, string $message): void {
    importTeamPlan($this->organisation);

    expect(fn () => Tenancy::run($this->organisation, fn () => app(StartCredentialImport::class)->handle($this->owner, $csv)))
        ->toThrow(ValidationException::class, $message);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'too big' => fn (): array => [IMPORT_HEADER.str_repeat("Key,Stripe,api_token,production,,,90,,,,\n", 30000), 'bigger than 1 MB'],
    'no rows' => fn (): array => [IMPORT_HEADER, '1 to 1000'],
    'too many rows' => fn (): array => [IMPORT_HEADER.str_repeat("Key,Stripe,api_token,production,,,90,,,,\n", 1001), '1 to 1000'],
    'no application column' => fn (): array => ["name,owner_email\nKey,siti@acme.test\n", 'name the columns'],
]);

it('runs on the queue in the organisation it was uploaded in, then deletes the file', function (): void {
    config(['queue.default' => 'database']);
    importTeamPlan($this->organisation);
    importSpreadsheet("Queued key,Stripe,api_token,production,,,90,,,,\n");

    expect(importedCredentials())->toBe([])->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles()[0])->toStartWith("org/{$this->organisation->id}/imports/");

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(array_map(fn (Credential $credential): string => $credential->name, importedCredentials()))->toBe(['Queued key'])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Tenancy::check())->toBeFalse();
});

it('imports nothing once the organisation is no longer on Team', function (): void {
    config(['queue.default' => 'database']);
    importTeamPlan($this->organisation, now()->addMinute());
    importSpreadsheet("Late key,Stripe,api_token,production,,,90,,,,\n");

    $this->travel(2)->minutes();
    app(Entitlements::class)->forget($this->organisation);
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(importedCredentials())->toBe([])->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('imports an uploaded file from the credentials page', function (): void {
    importTeamPlan($this->organisation);
    openOrganisationAs($this->owner, $this->organisation);

    Livewire::test(ListCredentials::class)
        ->callAction('import', ['file' => UploadedFile::fake()->createWithContent('credentials.csv', IMPORT_HEADER."Uploaded key,Stripe,api_token,production,,,90,,,,\n")])
        ->assertHasNoFormErrors()
        ->assertNotified('Importing 1 row. We\'ll let you know when it\'s done.');

    expect(array_map(fn (Credential $credential): string => $credential->name, importedCredentials()))->toBe(['Uploaded key']);
});

it('offers a template whose columns it reads back', function (): void {
    expect(array_keys(CredentialSpreadsheet::rows(CredentialSpreadsheet::template())[2]))->toBe(CredentialSpreadsheet::COLUMNS);
});
