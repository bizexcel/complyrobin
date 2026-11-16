<?php

it('runs on Postgres only, as three roles of the same database', function (): void {
    $connections = config('database.connections');

    // Laravel merges its own sqlite/mysql/mariadb/sqlsrv defaults in; AppServiceProvider drops them.
    expect(array_keys($connections))->toBe(['pgsql', 'pgsql_platform', 'pgsql_migrator'])
        ->and(array_values(array_unique(array_column($connections, 'driver'))))->toBe(['pgsql'])
        ->and(array_values(array_unique(array_column($connections, 'database'))))->toHaveCount(1)
        ->and(config('queue.batching.database'))->toBe('pgsql')
        ->and(config('queue.failed.database'))->toBe('pgsql');
});
