<?php

namespace Database\Factories;

use App\Models\User;
use Database\Factories\Concerns\MintsKlassciIdentifiers;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use MintsKlassciIdentifiers;

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
        $klassciId = $this->mintKlassciId('user');
        $enseignantId = $this->mintKlassciId('enseignant');

        return [
            'klassci_id' => $klassciId,
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
                // Le miroir KLASSCI de CET utilisateur : tirer un 3e nombre au
                // hasard en faisait un identifiant sans rapport avec la ligne
                // qui le porte, et un tirage de plus dans la plage en collision.
                'id' => $klassciId,
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
