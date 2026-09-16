<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Creneau;
use App\Models\Institution;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Creneau>
 */
final class CreneauFactory extends Factory
{
    protected $model = Creneau::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'training_session_id' => TrainingSession::factory(),
            'starts_at' => '2026-11-03 08:00:00',
            'ends_at' => '2026-11-03 12:00:00',
            'lieu' => 'Salle A — Cocody',
            'salle_virtuelle' => null,
            'formateur_id' => null,
        ];
    }

    /**
     * Créneau à distance : la salle virtuelle remplace le lieu.
     */
    public function aDistance(): self
    {
        return $this->state(fn (): array => [
            'lieu' => null,
            'salle_virtuelle' => 'https://visio.example.test/salle/abc',
        ]);
    }
}
