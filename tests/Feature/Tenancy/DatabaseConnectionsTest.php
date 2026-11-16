<?php

use Illuminate\Support\Facades\File;

it('runs on Postgres only, as three roles of the same database', function (): void {
    $connections = config('database.connections');

    // Laravel merges its own sqlite/mysql/mariadb/sqlsrv defaults in; AppServiceProvider drops them.
    expect(array_keys($connections))->toBe(['pgsql', 'pgsql_platform', 'pgsql_migrator'])
        ->and(array_values(array_unique(array_column($connections, 'driver'))))->toBe(['pgsql'])
        ->and(array_values(array_unique(array_column($connections, 'database'))))->toHaveCount(1)
        ->and(config('queue.batching.database'))->toBe('pgsql')
        ->and(config('queue.failed.database'))->toBe('pgsql');
});

it('gives every connection the direct endpoint from DB_DIRECT_HOST, which migrations then use', function (): void {
    $previous = [$_SERVER['DB_DIRECT_HOST'] ?? null, $_SERVER['DB_DIRECT_PORT'] ?? null];
    $_SERVER['DB_DIRECT_HOST'] = $_ENV['DB_DIRECT_HOST'] = 'postgres.internal';
    $_SERVER['DB_DIRECT_PORT'] = $_ENV['DB_DIRECT_PORT'] = '5433';

    try {
        $connections = (require config_path('database.php'))['connections'];
    } finally {
        [$_SERVER['DB_DIRECT_HOST'], $_SERVER['DB_DIRECT_PORT']] = [$_ENV['DB_DIRECT_HOST'], $_ENV['DB_DIRECT_PORT']] = $previous;
    }

    foreach (['pgsql', 'pgsql_platform', 'pgsql_migrator'] as $name) {
        expect($connections[$name]['direct'])->toMatchArray(['host' => 'postgres.internal', 'port' => '5433'], $name);
    }

    // Resolving connects, so point the direct endpoint at the real Postgres (direct when testing through PgBouncer).
    config(['database.connections.pgsql_migrator.direct' => [
        'host' => config('database.connections.pgsql_migrator.host'),
        'port' => env('DB_DIRECT_PORT', config('database.connections.pgsql_migrator.port')),
    ]]);
    DB::purge('pgsql_migrator');

    expect(app('migrator')->resolveConnection('pgsql_migrator')->getName())->toBe('pgsql_migrator::direct');
});

it('always emulates prepares on the tenant connection, pooled or direct, as its statements carry the tenant', function (): void {
    $tenant = config('database.connections.pgsql');

    expect($tenant['options'][PDO::ATTR_EMULATE_PREPARES])->toBeTrue()
        ->and($tenant['direct']['options'][PDO::ATTR_EMULATE_PREPARES])->toBeTrue();
});

it('keeps tenant settings out of every database session: only TenantContext writes set_config', function (): void {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'set_config('))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe(['app/Core/Tenancy/TenantContext.php']);
});
