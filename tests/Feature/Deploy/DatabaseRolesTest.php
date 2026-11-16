<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Log a connection in as another role for the rest of the test.
 */
function logConnectionInAs(string $connection, string $username, string $password): void
{
    config(["database.connections.{$connection}.username" => $username, "database.connections.{$connection}.password" => $password]);
    DB::purge($connection);
}

it('passes when every connection logs in with the right role', function (): void {
    $this->artisan('db:verify-roles')->assertSuccessful()->expectsOutputToContain('right role');
});

it('fails the deploy when the app would log in with a role that bypasses row-level security', function (): void {
    logConnectionInAs('pgsql', 'app_migrator', 'migrator');

    $this->artisan('db:verify-roles')->assertFailed()->expectsOutputToContain('pgsql logs in as app_migrator, which bypasses row-level security');
});

it('fails when the platform connection can\'t see across organisations', function (): void {
    logConnectionInAs('pgsql_platform', 'app_tenant', 'tenant');

    $this->artisan('db:verify-roles')->assertFailed()->expectsOutputToContain('doesn\'t bypass row-level security');
});

it('needs the database owner to create the logins', function (): void {
    config(['database.owner.username' => null]);

    $this->artisan('db:roles')->assertFailed()->expectsOutputToContain('Set DB_USERNAME and DB_PASSWORD');
});

it('creates or updates the logins as the owner, passing passwords only through psql\'s environment', function (): void {
    if (! Process::run(['psql', '--version'])->successful()) {
        $this->markTestSkipped('psql is not installed here (it is on Laravel Cloud and in CI).');
    }

    $this->artisan('db:roles')->assertSuccessful()->expectsOutputToContain('up to date');
    $this->artisan('db:verify-roles')->assertSuccessful();
});

it('hands psql the passwords in its environment, never on its command line', function (): void {
    Process::fake();

    $this->artisan('db:roles')->assertSuccessful();

    Process::assertRan(function ($process): bool {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'roles.sql')
            && ! str_contains($command, 'tenant') && ! str_contains($command, 'migrator')
            && $process->environment['DB_TENANT_PASSWORD'] === config('database.connections.pgsql.password')
            && $process->environment['PGUSER'] === config('database.owner.username');
    });
});
