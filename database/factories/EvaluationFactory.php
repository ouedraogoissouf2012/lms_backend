<?php

namespace Database\Factories;

use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

class EvaluationFactory extends Factory
{
    protected $model = Evaluation::class;

    public function definition(): array
    {
        return [
            'klassci_evaluation_id' => $this->faker->unique()->numberBetween(1, 10000),
            'klassci_matiere_id' => $this->faker->numberBetween(1, 50),
            'matiere_nom' => $this->faker->randomElement(['Mathématiques', 'Physique', 'Français', 'Anglais', 'Histoire']),
            'klassci_classe_id' => $this->faker->numberBetween(1, 20),
            'classe_nom' => $this->faker->randomElement(['Terminale A', 'Terminale D', 'Première S', 'Seconde C']),
            'klassci_enseignant_id' => $this->faker->numberBetween(1, 100),
            'enseignant_nom' => $this->faker->name(),
            'titre' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'type' => $this->faker->randomElement(['devoir', 'composition', 'interrogation', 'examen', 'tp', 'td', 'qcm']),
            'status' => $this->faker->randomElement(['brouillon', 'planifiee', 'en_cours', 'terminee']),
            'date_evaluation' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'duree_minutes' => $this->faker->randomElement([30, 60, 90, 120, 180]),
            'coefficient' => $this->faker->randomFloat(1, 1, 5),
            'bareme' => 20,
            'is_online' => $this->faker->boolean(80),
            'allow_retake' => $this->faker->boolean(20),
            'max_attempts' => $this->faker->numberBetween(1, 3),
            'shuffle_questions' => $this->faker->boolean(70),
            'show_results' => $this->faker->boolean(50),
            'is_published' => $this->faker->boolean(60),
            'notes_published' => $this->faker->boolean(30),
            'is_locked' => false,
            'locked_at' => null,
        ];
    }

    /**
     * État: brouillon
     */
    public function brouillon(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'brouillon',
            'is_published' => false,
        ]);
    }

    /**
     * État: planifiée
     */
    public function planifiee(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'planifiee',
            'is_published' => true,
            'date_evaluation' => now()->addDays(rand(1, 7)),
        ]);
    }

    /**
     * État: en cours
     */
    public function enCours(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'en_cours',
            'is_published' => true,
            'date_evaluation' => now()->subMinutes(rand(1, 30)),
        ]);
    }

    /**
     * État: terminée
     */
    public function terminee(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminee',
            'is_published' => true,
            'date_evaluation' => now()->subDays(rand(1, 30)),
        ]);
    }

    /**
     * Verrouillée
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_locked' => true,
            'locked_at' => now(),
        ]);
    }
}
