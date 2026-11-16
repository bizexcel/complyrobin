<?php

namespace Database\Seeders;

use App\Core\Identity\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database: the catalogues every environment needs and, on a developer's machine only,
     * a user to sign in as. Safe to run again.
     */
    public function run(): void
    {
        $this->call([PermissionSeeder::class, VendorSeeder::class]);

        // The password is the factory's well-known one, so never outside local development.
        if (app()->environment('local') && User::query()->where('email', 'test@example.com')->doesntExist()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
