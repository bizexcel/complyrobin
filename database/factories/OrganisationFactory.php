<?php

namespace Database\Factories;

use App\Core\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organisation>
 */
class OrganisationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 30, '').'-'.Str::lower(Str::random(5)),
        ];
    }
}
