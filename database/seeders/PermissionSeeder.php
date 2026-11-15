<?php

namespace Database\Seeders;

use App\Core\Access\Enums\Ability;
use App\Core\Access\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * One global permission per `Ability`. Idempotent: runs on every deploy (as the migrator).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Ability::cases() as $ability) {
            Permission::query()->firstOrCreate(['name' => $ability->value, 'guard_name' => 'web']);
        }
    }
}
