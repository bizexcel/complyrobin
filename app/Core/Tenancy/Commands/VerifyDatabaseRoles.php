<?php

namespace App\Core\Tenancy\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fails (and so fails the deploy) if a connection logs in with the wrong kind of role. Above all, the app's own
 * connection must be subject to row-level security: never a superuser, never BYPASSRLS, never the owner of the
 * database or its tables.
 */
#[Signature('db:verify-roles')]
#[Description('Check each database connection logs in with the right kind of role')]
class VerifyDatabaseRoles extends Command
{
    public function handle(): int
    {
        $problems = [
            ...$this->check('pgsql', bypassesRowSecurity: false, ownsTables: false),
            ...$this->check('pgsql_platform', bypassesRowSecurity: true, ownsTables: false),
            ...$this->check('pgsql_migrator', bypassesRowSecurity: true, ownsTables: true),
        ];

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $this->components->info('Every connection logs in with the right role.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function check(string $connection, bool $bypassesRowSecurity, bool $ownsTables): array
    {
        $role = DB::connection($connection)->selectOne(<<<'SQL'
            select current_user as name, rolsuper as superuser, rolbypassrls as bypasses,
                   (select pg_get_userbyid(datdba) from pg_database where datname = current_database()) = current_user as owns_database,
                   (select count(*) from pg_tables where schemaname = 'public' and tableowner = current_user) as tables
            from pg_roles where rolname = current_user
            SQL);

        $problems = [];
        $tables = (int) $role->tables;

        if (! $bypassesRowSecurity && ($role->superuser || $role->bypasses)) {
            $problems[] = "{$connection} logs in as {$role->name}, which bypasses row-level security.";
        }

        if (! $bypassesRowSecurity && $role->owns_database) {
            $problems[] = "{$connection} logs in as {$role->name}, the database owner.";
        }

        if ($bypassesRowSecurity && ! $role->superuser && ! $role->bypasses) {
            $problems[] = "{$connection} logs in as {$role->name}, which doesn't bypass row-level security.";
        }

        if (! $ownsTables && $tables > 0) {
            $problems[] = "{$connection} logs in as {$role->name}, which owns {$tables} tables.";
        }

        return $problems;
    }
}
