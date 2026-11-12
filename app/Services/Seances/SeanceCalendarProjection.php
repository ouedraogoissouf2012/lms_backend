<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use Carbon\Carbon;

/**
 * Une séance de la table `seances`, dans la forme que lisent le calendrier et
 * les écrans Séances et Visio : structures plate ET imbriquée, pour les deux
 * générations de vues.
 *
 * Écrite une fois pour les deux sources : le chemin du gestionnaire d'une
 * école créée depuis KLASSCI ({@see ManagerSeancesLocalFetcher}) et la source
 * locale ({@see LocalSeancesListReader}). Seuls les identifiants diffèrent :
 * ils viennent de {@see SeanceIdentite}.
 */
final class SeanceCalendarProjection
{
    /**
     * Durée SUPPOSÉE d'une séance, non mesurée : la table ne tient aucune heure
     * de fin, et le calendrier en exige une (D119).
     */
    public const DUREE_SUPPOSEE_HEURES = 2;

    /** @return array<string, mixed> */
    public function project(Seance $seance, SeanceIdentite $identite): array
    {
        $start = $seance->date_seance ? Carbon::parse($seance->date_seance)->copy() : null;
        $end = $start?->copy()->addHours(self::DUREE_SUPPOSEE_HEURES);
        $participantsCount = $seance->current_participants_count ?? 0;
        $startIso = $start?->toISOString();
        $endIso = $end?->toISOString();

        return [
            'id' => $identite->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'date_seance' => $start?->format('Y-m-d'),
            'date_debut' => $startIso,
            'date_fin' => $endIso,
            'heure_debut' => $start?->format('H:i'),
            'heure_fin' => $end?->format('H:i'),
            // Le seul lieu qui pose une fin ESTIMÉE : elle le dit, au client.
            'heure_fin_estimee' => $end !== null,
            'salle' => null,
            'programmation' => [
                'date' => $start?->format('Y-m-d'),
                'heure_debut' => $startIso,
                'heure_fin' => $endIso,
                'heure_fin_estimee' => $end !== null,
                'salle' => null,
            ],
            'matiere' => [
                'id' => $identite->matiereId,
                'libelle' => $identite->matiereNom,
                'nom' => $identite->matiereNom,
                'code' => null,
            ],
            'classe' => $this->classeBlock($identite),
            'enseignant' => [
                'id' => $identite->enseignantId,
                'nom' => $identite->enseignantNom,
                'prenom' => '',
            ],
            'visio' => $this->visioBlock($seance, $participantsCount),
            'statut' => $seance->visio_status ?? 'programme',
        ] + $this->flatVisioFields($seance, $participantsCount);
    }

    /**
     * Champs visio « plats » à la racine (compat frontend legacy) —
     * doublonnent le sous-objet `visio` construit par visioBlock().
     *
     * @return array<string, mixed>
     */
    private function flatVisioFields(Seance $seance, int $participantsCount): array
    {
        return [
            'visio_enabled' => $seance->visio_enabled,
            'visio_type' => $seance->visio_type,
            'visio_room_id' => $seance->visio_room_id,
            'visio_active' => $seance->visio_active,
            'visio_status' => $seance->visio_status,
            'visio_started_at' => $seance->visio_started_at?->toISOString(),
            'visio_ended_at' => $seance->visio_ended_at?->toISOString(),
            'visio_participants_count' => $participantsCount,
        ];
    }

    /** @return array<string, mixed> */
    private function classeBlock(SeanceIdentite $identite): array
    {
        return [
            'id' => $identite->classeId,
            'libelle' => $identite->classeNom,
            'nom' => $identite->classeNom,
            'name' => $identite->classeNom,
            'effectif' => $identite->classeEffectif,
        ];
    }

    /** @return array<string, mixed> */
    private function visioBlock(Seance $seance, int $participantsCount): array
    {
        return [
            'enabled' => $seance->visio_enabled,
            'active' => $seance->visio_active,
            'status' => $seance->visio_status,
            'room_id' => $seance->visio_room_id,
            'started_at' => $seance->visio_started_at?->toISOString(),
            'ended_at' => $seance->visio_ended_at?->toISOString(),
            'participants_count' => $participantsCount,
        ];
    }
}
