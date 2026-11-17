<?php

use App\Core\Access\Enums\SystemRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Filament\App\Pages\RotationEvidenceReports;
use App\Modules\Credentials\Actions\ClaimRotationRuns;
use App\Modules\Credentials\Actions\GenerateRotationEvidenceReport;
use App\Modules\Credentials\Actions\ReportRotationRun;
use App\Modules\Credentials\Jobs\BuildRotationEvidenceReport;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Notifications\RotationEvidenceReportReady;
use App\Modules\Credentials\Support\RotationEvidenceReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-06-01 04:00', 'UTC'));
    Notification::fake();
    Storage::fake('local');
    $this->owner = User::factory()->create(['name' => 'Aisyah Rahman']);
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
});

function prepareReport(?User $actor = null, int $days = 90, ?Organisation $organisation = null): void
{
    Tenancy::run($organisation ?? test()->organisation, fn () => app(GenerateRotationEvidenceReport::class)->handle($actor ?? test()->owner, $days));
}

/**
 * @param  array<string, mixed>  $credential
 */
function rotationOn(string $dueAt, string $rotatedAt, array $credential = [], string $notes = '', ?Organisation $organisation = null): CredentialRotation
{
    return Tenancy::run($organisation ?? test()->organisation, fn (): CredentialRotation => CredentialRotation::query()->create([
        'credential_id' => Credential::factory()->dueIn(60)->create(['application_id' => Application::factory()->create()->id, ...$credential])->id,
        'rotated_by_id' => test()->owner->id,
        'due_at' => $dueAt,
        'rotated_at' => $rotatedAt,
        'notes' => $notes,
    ]));
}

function buildReport(int $days = 90): RotationEvidenceReport
{
    return Tenancy::run(test()->organisation, fn (): RotationEvidenceReport => RotationEvidenceReport::build(test()->organisation, $days, now()));
}

it('is a Team feature', function (): void {
    prepareReport();
})->throws(ValidationException::class, 'part of Team');

it('needs the audit export permission', function (): void {
    onTeamPlan($this->organisation);

    prepareReport(memberWithRole($this->organisation, SystemRole::Editor));
})->throws(AuthorizationException::class);

it('keeps a PDF and a CSV under the organisation\'s own folder, and tells the member who asked', function (): void {
    onTeamPlan($this->organisation);
    rotationOn('2026-05-20 04:00', '2026-05-19 04:00');

    prepareReport();

    $files = Tenancy::run($this->organisation, fn () => $this->organisation->refresh()->getMedia('rotation-evidence'));
    expect($files)->toHaveCount(2)
        ->and($files->pluck('mime_type')->all())->toContain('application/pdf');

    foreach ($files as $file) {
        expect($file->getPathRelativeToRoot())->toStartWith("org/{$this->organisation->id}/");
        Storage::disk('local')->assertExists($file->getPathRelativeToRoot());
    }

    expect(Storage::disk('local')->get($files->firstWhere('mime_type', 'application/pdf')->getPathRelativeToRoot()))->toStartWith('%PDF-');
    Notification::assertSentTo($this->owner, RotationEvidenceReportReady::class);
});

it('lists the period\'s rotations of this organisation only, marks late ones and shows what is overdue now', function (): void {
    $globex = app(CreateOrganisation::class)->handle(User::factory()->create(), 'Globex');
    $onTime = rotationOn('2026-05-20 04:00', '2026-05-19 04:00', ['name' => 'Stripe live key']);
    $late = rotationOn('2026-05-01 04:00', '2026-05-04 04:00', ['name' => 'GitHub deploy key']);
    $tooOld = rotationOn('2026-01-01 04:00', '2026-01-02 04:00', ['name' => 'Old Mailgun key']);
    rotationOn('2026-05-20 04:00', '2026-05-19 04:00', ['name' => 'Globex payroll key'], organisation: $globex);
    $overdue = Tenancy::run($this->organisation, fn () => Credential::factory()->overdueBy(4)->create(['application_id' => Application::factory()->create()->id, 'name' => 'Postmark token']));

    $report = buildReport();
    $csv = $report->csv();
    $html = view('pdf.rotation-evidence', ['report' => $report])->render();

    expect(array_column($report->rotations, 'credential'))->toBe(['GitHub deploy key', 'Stripe live key'])
        ->and($report->onTime())->toBe(1)->and($report->late())->toBe(1)
        ->and($csv)->toContain('GitHub deploy key')->toContain(',Late,')->toContain(',"On time",')->not->toContain('Old Mailgun key')->not->toContain('Globex')
        ->and($csv)->toContain('2026-05-04 12:00')
        ->and($html)->toContain('Stripe live key')->toContain('Postmark token')->toContain($overdue->reference)->not->toContain('Globex payroll key')
        ->and(array_column($report->overdue, 'days_overdue'))->toBe([4]);
});

it('names the runner when a rotation was done automatically', function (): void {
    onTeamPlan($this->organisation);
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation, $this->owner, 'GitHub');
    automatedCredential($this->organisation, $this->owner, $runner, dueInDays: -1);
    $claim = app(ClaimRotationRuns::class)->handle($this->organisation, $token);
    app(ReportRotationRun::class)->handle($this->organisation, $token, [
        'run' => $claim['jobs'][0]['run'], 'outcome' => 'succeeded', 'rotated_at' => now()->toIso8601String(),
        'new_key' => ['id' => 'key_abc', 'hint' => 'AB12', 'expires_at' => null],
        'old_keys_expire_at' => now()->addHours(48)->toIso8601String(), 'deployments' => [],
    ]);

    expect(array_column(buildReport()->rotations, 'rotated_by'))->toBe(['Automatic rotation (GitHub)'])
        ->and(buildReport()->csv())->toContain('Automatic rotation (GitHub)');
});

it('neutralises spreadsheet formulas in the CSV', function (): void {
    rotationOn('2026-05-20 04:00', '2026-05-19 04:00', ['name' => '=HYPERLINK("https://evil.example")'], notes: '+SUM(A1:A9)');

    $rows = array_map(str_getcsv(...), array_filter(explode("\n", buildReport()->csv())));

    expect($rows[1][1])->toBe('\'=HYPERLINK("https://evil.example")')
        ->and($rows[1][10])->toBe("'+SUM(A1:A9)");
});

it('prepares one report at a time per organisation', function (): void {
    Queue::fake();
    onTeamPlan($this->organisation);
    $globexOwner = User::factory()->create();
    $globex = app(CreateOrganisation::class)->handle($globexOwner, 'Globex');
    onTeamPlan($globex);

    prepareReport();
    expect(fn () => prepareReport())->toThrow(ValidationException::class, 'already being prepared');
    prepareReport($globexOwner, organisation: $globex);

    Queue::assertPushed(BuildRotationEvidenceReport::class, 2);
    $job = Queue::pushed(BuildRotationEvidenceReport::class)->first();
    Tenancy::run($this->organisation, fn () => $job->failed(new RuntimeException('Out of memory')));

    prepareReport();
    Queue::assertPushed(BuildRotationEvidenceReport::class, 3);
});

it('releases the one-at-a-time lock once the report is done', function (): void {
    onTeamPlan($this->organisation);

    prepareReport();
    prepareReport();

    expect(Cache::lock(GenerateRotationEvidenceReport::lockName($this->organisation->id))->get())->toBeTrue();
});

it('offers reports only to members who export audit evidence', function (): void {
    onTeamPlan($this->organisation);

    openOrganisationAs(memberWithRole($this->organisation, SystemRole::Editor), $this->organisation);
    expect(RotationEvidenceReports::canAccess())->toBeFalse();

    openOrganisationAs($this->owner, $this->organisation);
    Livewire::test(RotationEvidenceReports::class)
        ->assertSee('No reports yet.')
        ->callAction('prepare', ['days' => 30])
        ->assertNotified('Preparing your report. We\'ll let you know when it\'s ready.');
});
