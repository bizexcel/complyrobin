<?php

namespace Database\Factories;

use App\Core\People\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside Tenancy::run(): the organisation comes from the tenant context.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
        ];
    }
}
