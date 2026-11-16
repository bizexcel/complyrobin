<?php

namespace App\Core\Tenancy\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Creates or updates the three database logins (database/sql/roles.sql) as the database owner, so every environment
 * sets itself up, including each Laravel Cloud preview, which starts with a new database where only the owner
 * exists. Idempotent; the passwords come from the connections' config and reach psql only through its environment.
 */
#[Signature('db:roles')]
#[Description('Create or update the app_migrator, app_tenant and app_platform logins as the database owner')]
class ApplyDatabaseRoles extends Command
{
    public function handle(): int
    {
        $owner = (array) config('database.owner');
        $tenant = (array) config('database.connections.pgsql');

        if (blank($owner['username'] ?? null)) {
            $this->components->error('Set DB_USERNAME and DB_PASSWORD to the database owner (Laravel Cloud injects them).');

            return self::FAILURE;
        }

        $passwords = [
            'DB_MIGRATOR_PASSWORD' => (string) config('database.connections.pgsql_migrator.password'),
            'DB_TENANT_PASSWORD' => (string) ($tenant['password'] ?? ''),
            'DB_PLATFORM_PASSWORD' => (string) config('database.connections.pgsql_platform.password'),
        ];

        // Postgres would accept an empty password by removing the login's password, which locks the app out.
        if (($missing = array_keys(array_filter($passwords, blank(...)))) !== []) {
            $this->components->error('Set '.implode(', ', $missing).': every database login needs a password.');

            return self::FAILURE;
        }

        // The direct endpoint when the app goes through a pooler.
        $host = data_get($tenant, 'direct.host') ?: $tenant['host'];
        $port = data_get($tenant, 'direct.port') ?: $tenant['port'];

        $result = Process::env([
            'PGHOST' => (string) $host,
            'PGPORT' => (string) $port,
            'PGDATABASE' => (string) $tenant['database'],
            'PGUSER' => (string) $owner['username'],
            'PGPASSWORD' => (string) ($owner['password'] ?? ''),
            'PGSSLMODE' => (string) ($tenant['sslmode'] ?? 'prefer'),
            ...$passwords,
        ])->run(['psql', '--no-psqlrc', '-v', 'ON_ERROR_STOP=1', '--quiet', '-f', database_path('sql/roles.sql')]);

        // psql exits with an error for a failed statement. A missing password makes roles.sql warn ("roles.sql: set …")
        // and quit cleanly, so look for that too. Notices from Postgres are not failures.
        if ($result->failed() || preg_match('/^roles\.sql: /m', $result->errorOutput()) === 1) {
            $this->components->error('Could not apply the database logins: '.trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        $this->components->info('Database logins are up to date.');

        return self::SUCCESS;
    }
}
