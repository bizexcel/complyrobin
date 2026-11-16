<?php

use App\Core\Organisations\Models\Organisation;
use Database\Seeders\PreviewSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('runs every deploy step, and adds no demo data outside a preview', function (): void {
    $this->artisan('app:deploy', ['--without-roles' => true])->assertSuccessful();

    expect(DB::connection('pgsql_migrator')->table('vendors')->count())->toBeGreaterThan(0)
        ->and(Organisation::query()->where('slug', PreviewSeeder::ORGANISATION_SLUG)->exists())->toBeFalse();
});

it('adds the demo organisation in a preview', function (): void {
    app()->detectEnvironment(fn (): string => 'preview');
    config(['preview.password' => 'correct-horse-battery', 'preview.mfa_secret' => 'JBSWY3DPEHPK3PXP']);

    $this->artisan('app:deploy', ['--without-roles' => true])->assertSuccessful();

    expect(Organisation::query()->where('slug', PreviewSeeder::ORGANISATION_SLUG)->exists())->toBeTrue();
});

it('stops before migrating when the app would bypass row-level security', function (): void {
    config(['database.connections.pgsql.username' => 'app_migrator', 'database.connections.pgsql.password' => 'migrator']);
    DB::purge('pgsql');

    $this->artisan('app:deploy', ['--without-roles' => true])->assertFailed()->expectsOutputToContain('db:verify-roles failed');
});
