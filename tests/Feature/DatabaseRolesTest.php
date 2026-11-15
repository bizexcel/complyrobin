<?php

use Illuminate\Support\Facades\DB;

it('runs the app as the non-bypass tenant role', function (): void {
    expect(DB::connection('pgsql')->scalar('select current_user'))->toBe('app_tenant')
        ->and(DB::connection('pgsql')->scalar('select rolbypassrls from pg_roles where rolname = current_user'))->toBeFalse()
        ->and(DB::connection('pgsql_platform')->scalar('select rolbypassrls from pg_roles where rolname = current_user'))->toBeTrue();
});

it('creates application tables owned by the migrator', function (): void {
    expect(DB::connection('pgsql')->scalar("select tableowner from pg_tables where tablename = 'users'"))->toBe('app_migrator');
});

it('uses uuid primary keys for users', function (): void {
    expect(DB::connection('pgsql')->scalar("select data_type from information_schema.columns where table_name = 'users' and column_name = 'id'"))->toBe('uuid');
});
