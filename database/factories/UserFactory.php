<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'klassci_id' => fake()->unique()->numberBetween(1, 10000),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => fake()->randomElement(['etudiant', 'enseignant', 'coordinateur']),
            'klassci_token' => Str::random(64),
            'klassci_data' => json_encode([
                'id' => fake()->numberBetween(1, 10000),
                'nom' => fake()->lastName(),
                'prenom' => fake()->firstName(),
            ]),
            'last_klassci_sync' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'etudiant',
        ]);
    }

    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'enseignant',
        ]);
    }

    public function coordinator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'coordinateur',
        ]);
    }
}
