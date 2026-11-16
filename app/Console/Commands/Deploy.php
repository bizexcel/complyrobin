<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\PreviewSeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Everything a deploy runs, in order, for production, staging and every preview alike: the database logins, a check
 * that the app can't bypass row-level security, migrations as the owner, the seeders, and the system roles. Previews
 * (APP_ENV=preview) also get their demo organisation. Stops at the first step that fails.
 */
#[Signature('app:deploy {--without-roles : Skip creating the database logins (they already exist)}')]
#[Description('Run every deploy step: logins, checks, migrations, seeders and system roles')]
class Deploy extends Command
{
    public function handle(): int
    {
        $steps = [
            ...($this->option('without-roles') ? [] : [['db:roles', []]]),
            ['db:verify-roles', []],
            ['migrate', ['--database' => 'pgsql_migrator', '--force' => true]],
            ['db:seed', ['--class' => PermissionSeeder::class, '--database' => 'pgsql_migrator', '--force' => true]],
            ['db:seed', ['--class' => VendorSeeder::class, '--database' => 'pgsql_migrator', '--force' => true]],
            ['access:sync-system-roles', []],
            ...(app()->environment('preview') ? [['db:seed', ['--class' => PreviewSeeder::class, '--database' => 'pgsql_migrator', '--force' => true]]] : []),
        ];

        foreach ($steps as [$command, $arguments]) {
            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->components->error("Deploy stopped: {$command} failed.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
