<?php

use App\Core\Entitlements\Entitlements;
use App\Core\Entitlements\Enums\Plan;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Enums\CredentialStatus;
use App\Modules\Credentials\Models\Credential;
use Database\Seeders\PreviewSeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->artisan('db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator']);
    config(['preview.password' => 'correct-horse-battery', 'preview.mfa_secret' => 'JBSWY3DPEHPK3PXP']);
});

function seedPreview(): void
{
    test()->artisan('db:seed', ['--class' => PreviewSeeder::class, '--database' => 'pgsql_migrator'])->assertSuccessful();
}

it('adds no demo data outside a preview environment', function (): void {
    seedPreview();

    expect(User::query()->where('email', 'demo@complyrobin.test')->exists())->toBeFalse()
        ->and(Organisation::query()->where('slug', PreviewSeeder::ORGANISATION_SLUG)->exists())->toBeFalse();
});

it('seeds a demo organisation on Team that the demo owner signs in to, and only once', function (): void {
    app()->detectEnvironment(fn (): string => 'preview');

    seedPreview();
    seedPreview();

    $owner = User::query()->where('email', 'demo@complyrobin.test')->sole();
    $organisation = Organisation::query()->where('slug', PreviewSeeder::ORGANISATION_SLUG)->sole();

    expect(Organisation::query()->where('name', 'Acme Preview')->count())->toBe(1)
        ->and(User::query()->where('email', 'demo@complyrobin.test')->count())->toBe(1)
        ->and(Hash::check('correct-horse-battery', $owner->password))->toBeTrue()
        ->and($owner->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and($owner->platform_role)->toBe(PlatformRole::Admin)
        ->and($owner->hasVerifiedEmail())->toBeTrue();

    Tenancy::run($organisation, function (): void {
        $statuses = Credential::query()->get()->map(fn (Credential $credential): CredentialStatus => $credential->status())->all();

        expect(app(Entitlements::class)->plan())->toBe(Plan::Team)
            ->and($statuses)->toHaveCount(4)
            ->toContain(CredentialStatus::Overdue)->toContain(CredentialStatus::DueSoon);
    });
});

it('refuses to create a demo account without a strong password and an authenticator secret', function (): void {
    app()->detectEnvironment(fn (): string => 'preview');
    config(['preview.password' => 'short']);

    $this->artisan('db:seed', ['--class' => PreviewSeeder::class, '--database' => 'pgsql_migrator']);
})->throws(RuntimeException::class, 'PREVIEW_PASSWORD');
