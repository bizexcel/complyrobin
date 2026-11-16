<?php

namespace App\Core\Tenancy\Testing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Parallel test runs on Postgres: each test process gets its own database (`{database}_test_{token}`), and all three
 * role connections use it. Laravel's own handling is switched off in phpunit.xml (LARAVEL_PARALLEL_TESTING_WITHOUT_
 * DATABASES): it moves only the default connection and creates the database with the app's login, which may not
 * create databases. Here the migrator creates it (it has CREATEDB locally and in CI only, never in production).
 */
final class ParallelDatabases
{
    /** @var list<string> */
    public const array CONNECTIONS = ['pgsql', 'pgsql_platform', 'pgsql_migrator'];

    private static ?string $prepared = null;

    public static function register(): void
    {
        ParallelTesting::setUpTestCase(function (): void {
            $token = ParallelTesting::token();

            if ($token === false) {
                return;
            }

            $database = config('database.connections.pgsql_migrator.database').'_test_'.$token;
            $isNewToThisProcess = self::$prepared !== $database;

            if ($isNewToThisProcess) {
                self::createIfMissing($database);
            }

            foreach (self::CONNECTIONS as $connection) {
                config(["database.connections.{$connection}.database" => $database]);
                DB::purge($connection);
            }

            if ($isNewToThisProcess) {
                self::grantDefaultPrivileges();
                self::$prepared = $database;
            }
        });
    }

    private static function createIfMissing(string $database): void
    {
        $migrator = DB::connection('pgsql_migrator');

        if (! $migrator->table('pg_database')->where('datname', $database)->exists()) {
            $migrator->statement('create database '.$migrator->getQueryGrammar()->wrap($database));
        }
    }

    /**
     * The per-database part of database/sql/roles.sql: tables the migrator creates are usable by the app roles. The
     * migrator owns the new database, so it owns its public schema too.
     */
    private static function grantDefaultPrivileges(): void
    {
        DB::connection('pgsql_migrator')->unprepared(<<<'SQL'
            alter default privileges in schema public grant select, insert, update, delete on tables to app_tenant, app_platform;
            alter default privileges in schema public grant usage, select on sequences to app_tenant, app_platform;
            SQL);
    }
}
