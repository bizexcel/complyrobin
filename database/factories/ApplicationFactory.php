<?php

namespace Database\Factories;

use App\Modules\Credentials\Enums\Criticality;
use App\Modules\Credentials\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' '.fake()->randomElement(['Cloud', 'API', 'Console']),
            'criticality' => fake()->randomElement(Criticality::cases()),
        ];
    }
}
