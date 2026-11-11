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
    public function __construct(
        private readonly SeanceCalendarProjection $projection,
    ) {}

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
            ->map(fn (Seance $seance): array => $this->projection->project($seance, SeanceIdentite::klassci($seance)));
    }
}
