<?php

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Seance;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Database\Factories\Concerns\MintsKlassciIdentifiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seance>
 */
class SeanceFactory extends Factory
{
    use MintsKlassciIdentifiers;

    protected $model = Seance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'klassci_seance_id' => $this->mintKlassciId('seance'),
            'klassci_matiere_id' => $this->mintKlassciId('matiere'),
            'klassci_classe_id' => $this->mintKlassciId('classe'),
            'klassci_enseignant_id' => $this->mintKlassciId('enseignant'),
            'enseignant_nom' => $this->faker->name(),
            'matiere_nom' => $this->faker->word(),
            'classe_nom' => $this->faker->randomElement(['1A', '2B', '3C', 'M1', 'M2']),
            'titre' => $this->faker->sentence(),
            'date_seance' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'classe_effectif' => $this->faker->numberBetween(10, 50),
            'visio_enabled' => false,
            'visio_type' => null,
            'visio_room_id' => null,
            'visio_status' => null,
            'visio_active' => false,
            'visio_started_at' => null,
            'visio_ended_at' => null,
            'institution_id' => Institution::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the seance has visio enabled (programmee state).
     */
    public function withVisio(): static
    {
        return $this->state(fn (array $attributes) => [
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_status' => 'programmee',
            'visio_room_id' => SecureVisioRoomIdGenerator::make(),
        ]);
    }

    /**
     * Indicate that the seance has an active visio (active state with started_at).
     */
    public function visioActive(): static
    {
        return $this->withVisio()->state(fn (array $attributes) => [
            'visio_status' => 'active',
            'visio_active' => true,
            'visio_started_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
        ]);
    }

    /**
     * Indicate that the seance visio has ended.
     */
    public function visioEnded(): static
    {
        return $this->visioActive()->state(fn (array $attributes) => [
            'visio_status' => 'terminee',
            'visio_active' => false,
            'visio_ended_at' => now(),
        ]);
    }

    /**
     * Set institution for the seance.
     */
    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (array $attributes) => [
            'institution_id' => $institution->id,
        ]);
    }

    /**
     * Set the klassci_enseignant_id to match a specific user's klassci_id.
     */
    public function forTeacher($klassciBelongingToTeacher): static
    {
        return $this->state(fn (array $attributes) => [
            'klassci_enseignant_id' => $klassciBelongingToTeacher,
        ]);
    }
}
