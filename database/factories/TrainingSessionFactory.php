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
     * Elles sont RELATIVES à aujourd'hui (#1208) : des dates absolues
     * pourrissent avec le calendrier, et un test qui lit la phase ou la fenêtre
     * d'inscription changerait de résultat le jour où elles passent. Par défaut,
     * la fenêtre d'inscription est ouverte ({@see self::inscriptionsOuvertes()}).
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
            ...$this->dates(-5, 5),
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

    /**
     * Fenêtre d'inscription OUVERTE aujourd'hui (#1208), quel que soit le jour du
     * test : les dates sont relatives, elles ne pourrissent pas avec le calendrier.
     */
    public function inscriptionsOuvertes(): self
    {
        return $this->fenetre(-5, 5);
    }

    /** Fenêtre d'inscription qui n'ouvre que dans quelques jours. */
    public function inscriptionsAVenir(): self
    {
        return $this->fenetre(3, 10);
    }

    /** Fenêtre d'inscription refermée hier. */
    public function inscriptionsCloses(): self
    {
        return $this->fenetre(-20, -1);
    }

    /**
     * Ouverture et fermeture à ces écarts, en jours, d'aujourd'hui ; les trois
     * dates suivantes viennent après, pour rester ORDONNÉES.
     */
    public function fenetre(int $ouverture, int $fermeture): self
    {
        return $this->state(fn (): array => $this->dates($ouverture, $fermeture));
    }

    /** @return array<string, string> */
    private function dates(int $ouverture, int $fermeture): array
    {
        return [
            'enrollment_opens_at' => today()->addDays($ouverture)->toDateString(),
            'enrollment_closes_at' => today()->addDays($fermeture)->toDateString(),
            'starts_on' => today()->addDays($fermeture + 5)->toDateString(),
            'ends_on' => today()->addDays($fermeture + 100)->toDateString(),
            'certificate_available_at' => today()->addDays($fermeture + 110)->toDateString(),
        ];
    }
}
