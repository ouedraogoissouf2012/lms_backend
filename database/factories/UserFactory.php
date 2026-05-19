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
        $enseignantId = fake()->unique()->numberBetween(1, 10000);

        return [
            'klassci_id' => fake()->unique()->numberBetween(1, 10000),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => fake()->randomElement(['etudiant', 'enseignant', 'coordinateur']),
            // Issue #119 — `klassci_enseignant_id` est la colonne dédiée d'autorité ;
            // alignée par défaut avec `klassci_data['enseignant_id']` pour que les
            // tests legacy qui peuplent la factory sans state explicite restent verts.
            'klassci_enseignant_id' => $enseignantId,
            'klassci_token' => Str::random(64),
            'klassci_data' => json_encode([
                'id' => fake()->numberBetween(1, 10000),
                'nom' => fake()->lastName(),
                'prenom' => fake()->firstName(),
                'enseignant_id' => $enseignantId,
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

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }
}
