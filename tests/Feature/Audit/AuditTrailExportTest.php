<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Audit\Actions\ExportAuditTrail;
use App\Core\Audit\Models\Activity;
use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Actions\CreateNotificationChannel;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Models\Person;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\AuditTrail;
use App\Filament\App\Resources\Credentials\Pages\ViewCredential;
use App\Filament\App\Resources\Credentials\RelationManagers\HistoryRelationManager;
use App\Modules\Credentials\Actions\CreateCalendarFeed;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create(['name' => 'Aisyah Rahman']);
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    $this->actingAs($this->owner);
});

function auditTeamPlan(Organisation $organisation): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $organisation, Module::Credentials, now()->subDay());
}

function exportedTrail(?User $actor = null, int $days = 90): string
{
    $response = Tenancy::run(test()->organisation, fn () => app(ExportAuditTrail::class)->handle($actor ?? test()->owner, $days));

    // The download is written after the action returns, outside the organisation.
    return TestResponse::fromBaseResponse($response)->streamedContent();
}

it('is a Team feature', function (): void {
    exportedTrail();
})->throws(ValidationException::class, 'part of Team');

it('needs the audit export permission', function (): void {
    auditTeamPlan($this->organisation);

    exportedTrail(memberWithRole($this->organisation, SystemRole::Editor));
})->throws(AuthorizationException::class);

it('exports who changed what, for this organisation only, and records the export', function (): void {
    auditTeamPlan($this->organisation);
    $credential = Tenancy::run($this->organisation, function (): Credential {
        $credential = Credential::factory()->create(['application_id' => Application::factory()->create()->id, 'name' => 'Stripe live key', 'rotation_days' => 90]);
        $credential->update(['rotation_days' => 30, 'owner_id' => Person::factory()->create()->id]);

        return $credential;
    });
    $globexOwner = User::factory()->create();
    $globex = app(CreateOrganisation::class)->handle($globexOwner, 'Globex');
    Tenancy::run($globex, fn () => Credential::factory()->create(['application_id' => Application::factory()->create()->id, 'name' => 'Globex payroll key']));

    $csv = exportedTrail();
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", $csv)));

    expect($rows[0])->toBe(['When', 'Who', 'Event', 'Record type', 'Record', 'Changes'])
        ->and($csv)->toContain('Stripe live key')->toContain("Aisyah Rahman <{$this->owner->email}>")
        ->toContain('rotation_days: 90 → 30')->toContain('owner: changed')->toContain($credential->reference)
        ->not->toContain('Globex payroll key')->not->toContain($credential->id)->not->toContain($credential->application_id)
        ->and(Tenancy::run($this->organisation, fn () => Activity::query()->where('event', 'exported')->sole()->description))->toBe('Exported the audit trail (last 90 days)');
});

it('never contains hidden or encrypted values', function (): void {
    auditTeamPlan($this->organisation);
    Tenancy::run($this->organisation, function (): void {
        app(CreateNotificationChannel::class)->handle($this->owner, ['type' => 'slack', 'name' => '#ops', 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/very-secret']);
        app(CreateCalendarFeed::class)->handle($this->owner);
    });
    $feedToken = DB::connection('pgsql_migrator')->table('calendar_feeds')->value('token');

    expect(exportedTrail())->toContain('#ops')->toContain('Calendar Feed')
        ->not->toContain('very-secret')->not->toContain('hooks.slack.com')->not->toContain($feedToken);
});

it('neutralises spreadsheet formulas', function (): void {
    auditTeamPlan($this->organisation);
    Tenancy::run($this->organisation, fn () => Person::factory()->create(['name' => '=HYPERLINK("https://evil.example")', 'email' => null]));

    $rows = array_map(str_getcsv(...), array_filter(explode("\n", exportedTrail())));
    $person = collect($rows)->first(fn (array $row): bool => $row[3] === 'Person');

    expect($person[4])->toBe('\'=HYPERLINK("https://evil.example")');
});

it('exports only the chosen period, at most a year', function (): void {
    auditTeamPlan($this->organisation);
    $this->travel(-200)->days();
    Tenancy::run($this->organisation, fn () => Person::factory()->create(['name' => 'Long-gone contractor']));
    $this->travelBack();

    expect(exportedTrail(days: 90))->not->toContain('Long-gone contractor')
        ->and(exportedTrail(days: 365))->toContain('Long-gone contractor');
    expect(fn (): string => exportedTrail(days: 400))->toThrow(ValidationException::class);
});

it('shows the trail and downloads the CSV from the audit trail page', function (): void {
    auditTeamPlan($this->organisation);
    $person = Tenancy::run($this->organisation, fn () => Person::factory()->create(['name' => 'Siti Nurhaliza']));

    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);
    expect(AuditTrail::canAccess())->toBeFalse();

    openOrganisationAs($this->owner, $this->organisation);
    Livewire::test(AuditTrail::class)
        ->assertCanSeeTableRecords(Activity::query()->where('subject_id', $person->id)->get())
        ->assertSee('Siti Nurhaliza')
        ->callAction('export', ['days' => 30])
        ->assertFileDownloaded();
});

it('shows a credential\'s own history on its page', function (): void {
    [$credential, $other] = Tenancy::run($this->organisation, function (): array {
        $credential = Credential::factory()->create(['application_id' => Application::factory()->create()->id, 'rotation_days' => 90]);
        $credential->update(['rotation_days' => 45]);

        return [$credential, Credential::factory()->create(['application_id' => $credential->application_id])];
    });
    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Viewer), $this->organisation);

    Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $credential, 'pageClass' => ViewCredential::class])
        ->assertCanSeeTableRecords($credential->activitiesAsSubject()->get())
        ->assertCanNotSeeTableRecords($other->activitiesAsSubject()->get())
        ->assertSee('rotation_days: 90 → 45');
});
