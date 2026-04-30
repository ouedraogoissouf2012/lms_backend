<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClasseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->unique()->bothify('Class ??#'),
            'niveau_etude' => fake()->randomElement(['L1', 'L2', 'L3', 'M1', 'M2']),
            'effectif' => fake()->numberBetween(20, 50),
        ];
    }
}
