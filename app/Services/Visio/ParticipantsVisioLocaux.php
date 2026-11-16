<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\ParticipantsDeSeanceLocale;
use App\Services\Seances\SeanceCalendarProjection;
use Illuminate\Support\Carbon;

/**
 * Le roster et l'horaire de la liste des participants d'une séance LOCALE (#1120),
 * sans appel KLASSCI.
 *
 * - Le roster : les élèves actifs de la classe, par la règle locale
 *   ({@see ParticipantsDeSeanceLocale}, celle du détail d'une séance locale), dans la
 *   forme que lit {@see VisioParticipantsEnricher} (`id`, `nom`, `prenom`, `email`).
 * - L'horaire : `date_seance`, et la fin estimée de l'affichage (début + durée
 *   supposée, {@see SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES}).
 */
final class ParticipantsVisioLocaux
{
    public function __construct(private readonly ParticipantsDeSeanceLocale $participants) {}

    /**
     * Le roster, la durée en minutes, le début et la fin (ISO).
     *
     * @return array{0: list<array<string, mixed>>, 1: int|float, 2: string|null, 3: string|null}
     */
    public function rosterEtHoraire(Seance $seance): array
    {
        $roster = array_map(static fn (User $eleve): array => [
            'id' => $eleve->id,
            'nom' => $eleve->name,
            'prenom' => '',
            'email' => $eleve->email,
        ], $this->participants->de($seance)['eleves']);

        $debut = $seance->date_seance ? Carbon::parse($seance->date_seance) : null;
        $fin = $debut?->copy()->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES);

        return [$roster, SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES * 60, $debut?->toIso8601String(), $fin?->toIso8601String()];
    }
}
