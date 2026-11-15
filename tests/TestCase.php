<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;

    /**
     * Truncate as the schema owner. Truncation (not transactions) is used because the tenant
     * and platform connections are separate sessions that must see each other's committed rows.
     *
     * @var array<int, string>
     */
    protected array $connectionsToTruncate = ['pgsql_migrator'];

    /**
     * Migrate as the schema owner, exactly as production does.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_migrator',
            '--drop-views' => true,
            '--drop-types' => true,
            '--seed' => false,
        ];
    }
}
