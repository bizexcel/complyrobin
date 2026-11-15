<?php

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\AttachRotationEvidence;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Models\CredentialRotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->organisation = app(CreateOrganisation::class)->handle($this->owner, 'Acme');
    $this->credential = Tenancy::run($this->organisation, fn () => Credential::factory()->create([
        'application_id' => Application::factory()->create()->id,
    ])->refresh());
});

function onTeam(): void
{
    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), test()->organisation, Module::Credentials, now()->subDay());
}

/**
 * @param  list<UploadedFile>  $files
 */
function rotateWithEvidence(array $files): CredentialRotation
{
    return Tenancy::run(test()->organisation, fn (): CredentialRotation => app(RecordRotation::class)->handle(test()->owner, test()->credential, ['evidence' => $files]));
}

it('stores evidence under the organisation\'s own folder on Team', function (): void {
    onTeam();

    $rotation = rotateWithEvidence([evidencePdf('vendor-console.pdf'), UploadedFile::fake()->image('screenshot.png')]);
    $files = Tenancy::run($this->organisation, fn (): MediaCollection => $rotation->getMedia('evidence'));

    expect($files)->toHaveCount(2);
    foreach ($files as $file) {
        expect($file->getPathRelativeToRoot())->toStartWith("org/{$this->organisation->id}/");
        Storage::disk('local')->assertExists($file->getPathRelativeToRoot());
    }
});

it('keeps evidence a Team feature', function (): void {
    rotateWithEvidence([evidencePdf()]);
})->throws(ValidationException::class, 'Team');

it('still records a rotation on Free when no evidence is attached', function (): void {
    expect(rotateWithEvidence([])->exists)->toBeTrue();
});

it('refuses files that aren\'t documents or images, or are too big', function (Closure $file): void {
    onTeam();

    rotateWithEvidence([$file()]);
})->with([
    'an SVG' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'an HTML page' => fn () => UploadedFile::fake()->createWithContent('page.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'),
    'over 10 MB' => fn () => UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf'),
])->throws(ValidationException::class);

it('allows at most 5 evidence files on a rotation', function (): void {
    onTeam();
    $rotation = rotateWithEvidence([evidencePdf('a.pdf'), evidencePdf('b.pdf'), evidencePdf('c.pdf'), evidencePdf('d.pdf')]);

    Tenancy::run($this->organisation, fn () => app(AttachRotationEvidence::class)->handle($this->owner, $rotation, [evidencePdf('e.pdf'), evidencePdf('f.pdf')]));
})->throws(ValidationException::class, 'at most 5');

it('still lets old evidence be downloaded after a downgrade, through a short-lived signed link', function (): void {
    // A real local disk, not Storage::fake(), so the link is the app's own signed storage route.
    $root = storage_path('framework/testing/evidence-'.Str::random(8));
    Storage::forgetDisk('local');
    config(['filesystems.disks.local.root' => $root]);
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

    app(GrantModule::class)->handle(User::factory()->create(['platform_role' => PlatformRole::Admin]), $this->organisation, Module::Credentials, now()->subDay(), now()->addMinute());
    $rotation = rotateWithEvidence([evidencePdf()]);
    $this->travel(2)->minutes();
    app(Entitlements::class)->forget($this->organisation);

    $url = Tenancy::run($this->organisation, fn (): string => $rotation->getFirstMedia('evidence')->getTemporaryUrl(now()->addMinutes(5)));

    expect($url)->toContain('signature=')->toContain('expires=');
    $this->get($url)->assertOk();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});
