<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSession>
 */
final class TrainingSessionFactory extends Factory
{
    protected $model = TrainingSession::class;

    /**
     * Les cinq dates par défaut sont ORDONNÉES : la contrainte de base refuse
     * toute autre combinaison, et une factory qui produirait des lignes
     * invalides rendrait chaque test dépendant d'un `create()` chanceux.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'program_id' => Program::factory(),
            'libelle' => 'Promotion '.$this->faker->monthName().' '.$this->faker->year(),
            'status' => TrainingSessionStatus::Brouillon,
            'enrollment_opens_at' => '2026-10-01',
            'enrollment_closes_at' => '2026-10-20',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-02-28',
            'certificate_available_at' => '2027-03-15',
            'min_enrollments' => 8,
            'tarif' => 110000,
            'devise' => 'XOF',
            'heures_stagiaires' => 120,
        ];
    }

    public function publiee(): self
    {
        return $this->state(fn (): array => ['status' => TrainingSessionStatus::Publiee]);
    }

    /**
     * Sans aucune date : prouve que les nuls restent permis.
     */
    public function sansDates(): self
    {
        return $this->state(fn (): array => [
            'enrollment_opens_at' => null,
            'enrollment_closes_at' => null,
            'starts_on' => null,
            'ends_on' => null,
            'certificate_available_at' => null,
        ]);
    }
}
