<?php

namespace Database\Seeders;

use App\Core\Entitlements\Actions\GrantModule;
use App\Core\Entitlements\Enums\Module;
use App\Core\Identity\Enums\PlatformRole;
use App\Core\Identity\Models\User;
use App\Core\Organisations\Actions\CreateOrganisation;
use App\Core\Organisations\Models\Organisation;
use App\Core\People\Actions\CreatePerson;
use App\Core\Tenancy\Facades\Tenancy;
use App\Modules\Credentials\Actions\CreateApplication;
use App\Modules\Credentials\Actions\CreateCredential;
use App\Modules\Credentials\Actions\RecordRotation;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A demo organisation for preview environments (APP_ENV=preview), so reviewers have something to look at: Acme on
 * Team, with people, applications and credentials that are overdue, due soon, rotated and without an owner. The demo
 * owner signs in with PREVIEW_PASSWORD and an authenticator app set up with PREVIEW_MFA_SECRET, and is also staff, for
 * the admin panel. Does nothing anywhere else; safe to run on every deploy. Run as the migrator, as deploys do.
 */
class PreviewSeeder extends Seeder
{
    public const string ORGANISATION_SLUG = 'acme-preview';

    public function run(
        CreateOrganisation $createOrganisation,
        GrantModule $grantModule,
        CreatePerson $createPerson,
        CreateApplication $createApplication,
        CreateCredential $createCredential,
        RecordRotation $recordRotation,
    ): void {
        if (! app()->environment('preview')) {
            $this->command?->warn('Not a preview environment (APP_ENV=preview): no demo data.');

            return;
        }

        $password = (string) config('preview.password');
        $secret = (string) config('preview.mfa_secret');

        if (strlen($password) < 12 || $secret === '') {
            throw new RuntimeException('Set PREVIEW_PASSWORD (12 characters or more) and PREVIEW_MFA_SECRET on the preview automation.');
        }

        // Through the app's own connection, as a real sign-up is: deploys run seeders as the migrator, and an
        // organisation created in the migrator's transaction is invisible to the app's session until it commits.
        DB::usingConnection('pgsql', function () use ($password, $secret, $createOrganisation, $grantModule, $createPerson, $createApplication, $createCredential, $recordRotation): void {
            $owner = User::query()->firstOrNew(['email' => (string) config('preview.owner_email')]);
            $owner->forceFill([
                'name' => $owner->name ?? 'Demo Owner',
                'password' => Hash::make($password),
                'email_verified_at' => $owner->email_verified_at ?? now(),
                'platform_role' => PlatformRole::Admin,
                'has_email_authentication' => false,
            ])->save();
            $owner->saveAppAuthenticationSecret($secret);

            if (Organisation::query()->where('slug', self::ORGANISATION_SLUG)->exists()) {
                return;
            }

            $organisation = $createOrganisation->handle($owner, 'Acme Preview');
            $grantModule->handle($owner, $organisation, Module::Credentials, now()->subDay(), note: 'Preview demo data');

            Tenancy::run($organisation, function () use ($owner, $createPerson, $createApplication, $createCredential, $recordRotation): void {
                $siti = $createPerson->handle($owner, ['name' => 'Siti Rahman', 'email' => 'siti@example.com', 'job_title' => 'Head of Engineering']);
                $ahmad = $createPerson->handle($owner, ['name' => 'Ahmad Hakim', 'email' => 'ahmad@example.com', 'job_title' => 'Platform Engineer']);
                $application = fn (string $vendor, string $criticality): Application => $createApplication->handle($owner, [
                    'vendor_id' => Vendor::query()->where('slug', $vendor)->value('id'),
                    'name' => (string) Vendor::query()->where('slug', $vendor)->value('name'),
                    'criticality' => $criticality,
                    'owner_id' => $siti->getKey(),
                ]);
                [$stripe, $github, $aws] = [$application('stripe', 'critical'), $application('github', 'high'), $application('aws', 'high')];

                $createCredential->handle($owner, [
                    'application_id' => $stripe->getKey(), 'name' => 'Stripe live secret key', 'type' => 'api_token', 'environment' => 'production',
                    'owner_id' => $siti->getKey(), 'backup_owner_id' => $ahmad->getKey(), 'used_in' => 'Laravel Cloud production: STRIPE_SECRET',
                    'rotation_days' => 90, 'last_rotated_at' => now()->subDays(100),
                ]);
                $createCredential->handle($owner, [
                    'application_id' => $github->getKey(), 'name' => 'GitHub deploy key', 'type' => 'ssh_key', 'environment' => 'production',
                    'owner_id' => $ahmad->getKey(), 'backup_owner_id' => $siti->getKey(), 'rotation_days' => 180, 'last_rotated_at' => now()->subDays(170),
                ]);
                $ciKey = $createCredential->handle($owner, [
                    'application_id' => $aws->getKey(), 'name' => 'AWS CI access key', 'type' => 'service_account', 'environment' => 'production',
                    'owner_id' => $ahmad->getKey(), 'rotation_days' => 90, 'last_rotated_at' => now()->subDays(110),
                ]);
                $recordRotation->handle($owner, $ciKey, ['rotated_at' => now()->subDays(20), 'notes' => 'New key created in IAM; CI secret updated.']);
                $createCredential->handle($owner, [
                    'application_id' => $stripe->getKey(), 'name' => 'Stripe webhook signing secret', 'type' => 'webhook_secret', 'environment' => 'staging',
                    'rotation_days' => 365, 'last_rotated_at' => now()->subDays(30),
                ]);
            });
        });
    }
}
