<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => Str::slug(fake()->unique()->company()),
            'name' => fake()->company(),
            'klassci_api_url' => fake()->url(),
            'klassci_api_token_encrypted' => Str::random(64),
            'logo_url' => fake()->imageUrl(),
            'primary_color' => fake()->hexColor(),
            'is_active' => true,
            'settings' => json_encode([
                'timezone' => 'UTC',
                'academic_year' => 2026,
            ]),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
