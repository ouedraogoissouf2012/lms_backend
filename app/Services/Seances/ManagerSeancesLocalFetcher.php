<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fournit la liste des séances à venir aux managers (coordinateurs/admins)
 * depuis le cache LOCAL, sans parcourir toutes les matières KLASSCI.
 *
 * ## Issue #473/#475 — extrait de {@see UpcomingSeancesFetcher} (§1.1 ≤300 lignes)
 *
 * Le chemin manager est une responsabilité distincte du walk KLASSCI (étudiant/
 * enseignant) : il lit la table `seances` synchronisée et la projette dans la
 * forme attendue par les vues calendrier/visio. En contexte HTTP, le scope
 * global `institution` isole déjà les séances du tenant courant.
 *
 * @see UpcomingSeancesFetcher (walk KLASSCI, orchestrateur)
 */
final class ManagerSeancesLocalFetcher
{
    private const DEFAULT_SEANCE_DURATION_HOURS = 2;

    /**
     * Liste les séances actives du cache local dans la fenêtre demandée,
     * filtrées optionnellement par enseignant et/ou classe.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        $start = Carbon::parse($dateDebut)->startOfDay();
        $end = Carbon::parse($dateFin)->endOfDay();

        $query = Seance::query()
            ->withConnectedParticipantsCount()
            ->where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->whereNotNull('date_seance')
            ->whereBetween('date_seance', [$start, $end]);

        if ($teacherId !== null) {
            $query->where(function ($query) use ($teacherId): void {
                $query->where('klassci_enseignant_id', $teacherId)
                    ->orWhere('created_by', $teacherId);
            });
        }

        if ($classeId !== null) {
            $query->where('klassci_classe_id', $classeId);
        }

        return $query
            ->orderBy('date_seance')
            ->get()
            ->map(fn (Seance $seance): array => $this->mapLocalSeance($seance));
    }

    /**
     * Projette une séance locale dans la forme legacy attendue par le frontend
     * (structures plate + imbriquée pour compatibilité calendrier/visio).
     *
     * @return array<string, mixed>
     */
    private function mapLocalSeance(Seance $seance): array
    {
        $start = $seance->date_seance ? Carbon::parse($seance->date_seance)->copy() : null;
        $end = $start?->copy()->addHours(self::DEFAULT_SEANCE_DURATION_HOURS);
        $date = $start?->format('Y-m-d');
        $startIso = $start?->toISOString();
        $endIso = $end?->toISOString();
        $participantsCount = $seance->current_participants_count ?? 0;

        return [
            'id' => $seance->klassci_seance_id ?? $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'date_seance' => $date,
            'date_debut' => $startIso,
            'date_fin' => $endIso,
            'heure_debut' => $start?->format('H:i'),
            'heure_fin' => $end?->format('H:i'),
            'salle' => null,
            'programmation' => [
                'date' => $date,
                'heure_debut' => $startIso,
                'heure_fin' => $endIso,
                'salle' => null,
            ],
            'matiere' => [
                'id' => $seance->klassci_matiere_id,
                'libelle' => $seance->matiere_nom ?? 'N/A',
                'nom' => $seance->matiere_nom ?? 'N/A',
                'code' => null,
            ],
            'classe' => [
                'id' => $seance->klassci_classe_id,
                'libelle' => $seance->classe_nom ?? 'N/A',
                'nom' => $seance->classe_nom ?? 'N/A',
                'name' => $seance->classe_nom ?? 'N/A',
                'effectif' => $seance->classe_effectif ?? 0,
            ],
            'enseignant' => [
                'id' => $seance->klassci_enseignant_id,
                'nom' => $seance->enseignant_nom ?? 'Non assigné',
                'prenom' => '',
            ],
            'visio_enabled' => $seance->visio_enabled,
            'visio_type' => $seance->visio_type,
            'visio_room_id' => $seance->visio_room_id,
            'visio_active' => $seance->visio_active,
            'visio_status' => $seance->visio_status,
            'visio_started_at' => $seance->visio_started_at?->toISOString(),
            'visio_ended_at' => $seance->visio_ended_at?->toISOString(),
            'visio_participants_count' => $participantsCount,
            'visio' => [
                'enabled' => $seance->visio_enabled,
                'active' => $seance->visio_active,
                'status' => $seance->visio_status,
                'room_id' => $seance->visio_room_id,
                'started_at' => $seance->visio_started_at?->toISOString(),
                'ended_at' => $seance->visio_ended_at?->toISOString(),
                'participants_count' => $participantsCount,
            ],
            'statut' => $seance->visio_status ?? 'programme',
        ];
    }
}
