<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Enums\Role;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly VisioActorAuthorization $autorisation,
    ) {}

    /**
     * Liste les séances actives du cache local dans la fenêtre demandée,
     * filtrées optionnellement par enseignant et/ou classe.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $gestion, string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
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
            $this->filtrerParEnseignant($query, $gestion, $teacherId);
        }

        if ($classeId !== null) {
            $query->where('klassci_classe_id', $classeId);
        }

        return $query
            ->orderBy('date_seance')
            ->get()
            ->map(fn (Seance $seance): array => $this->projection->project($seance, SeanceIdentite::klassci($seance)));
    }

    /**
     * `$teacherId` est l'id d'enseignant KLASSCI que rend l'annuaire : il désigne
     * un ENSEIGNANT de l'école du gestionnaire, dont les séances sont celles qu'il
     * gère (la règle de `canManage`). Sans compte LMS : son identité KLASSCI
     * seule, jamais `created_by`, qui est un id de compte LMS.
     *
     * @param  Builder<Seance>  $seances
     */
    private function filtrerParEnseignant(Builder $seances, User $gestion, int $teacherId): void
    {
        $enseignant = $this->enseignant($gestion, $teacherId);

        if ($enseignant === null) {
            $seances->where('klassci_enseignant_id', $teacherId);

            return;
        }

        $this->autorisation->limitToTeacherSeances($seances, $enseignant);
    }

    /**
     * Par l'id d'enseignant KLASSCI, puis à défaut par l'id d'utilisateur KLASSCI,
     * que le repli de l'annuaire rend (`LocalTeacherDirectory`). Toujours dans
     * l'établissement du gestionnaire ; sans établissement, aucun.
     */
    private function enseignant(User $gestion, int $teacherId): ?User
    {
        if (! is_int($gestion->institution_id)) {
            return null;
        }

        foreach (['klassci_enseignant_id', 'klassci_id'] as $identite) {
            $enseignant = User::query()
                ->where('institution_id', $gestion->institution_id)
                ->whereIn('role', Role::Enseignant->aliases())
                ->where($identite, $teacherId)
                ->first();

            if ($enseignant !== null) {
                return $enseignant;
            }
        }

        return null;
    }
}
