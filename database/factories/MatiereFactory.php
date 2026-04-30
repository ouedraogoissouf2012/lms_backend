<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class MatiereFactory extends Factory
{
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'klassci_id' => fake()->unique()->numberBetween(1, 10000),
            'code' => fake()->unique()->bothify('MAT-???-###'),
            'libelle' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'coefficient' => fake()->numberBetween(1, 5),
            'credit' => fake()->numberBetween(1, 6),
        ];
    }
}
