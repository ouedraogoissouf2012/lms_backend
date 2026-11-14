<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les listes de séances d'une école créée depuis le LMS, ou basculée
 * (ADR-EI-01, lot 3d). Aucun jeton, aucun appel à KLASSCI.
 *
 * Qui voit quoi (D119) : {@see SeancesLocalesVisibles}, la règle partagée avec le
 * détail d'une séance.
 *
 * Une séance locale n'a pas de colonne « enseignant » : seulement son auteur et
 * sa classe. Une séance sans classe n'est montrée à aucun apprenant.
 *
 * Toutes les lectures sont bornées EXPLICITEMENT à l'établissement : le scope
 * global est fail-open sans tenant. Seules les séances actives d'une fenêtre
 * de dates sont lues, comme dans l'école créée depuis KLASSCI.
 */
final class LocalSeancesListReader implements SeancesListReader
{
    public function __construct(
        private readonly SeancesLocalesVisibles $visibles,
    ) {}

    public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId): array
    {
        $dateDebut = Carbon::now()->format('Y-m-d');
        $dateFin = Carbon::now()->addDays($days)->format('Y-m-d');
        $institution = $user->institution_id;

        $seances = [];
        if (is_int($institution)) {
            $visibles = $this->visibles->pour($user, $institution);
            // Comme dans l'école créée depuis KLASSCI : l'auteur ne filtre que
            // pour la gestion ; la classe, pour tous, dans ce qu'ils voient déjà.
            if ($teacherId !== null && $user->isManager()) {
                $visibles->where('created_by', $teacherId);
            }
            if ($classeId !== null) {
                $visibles->where('classe_id', $classeId);
            }
            $seances = $this->lire($visibles, $dateDebut, $dateFin, $institution);
        }

        return [
            'data' => $seances,
            'meta' => [
                'total_seances' => count($seances),
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'filtres' => [
                    'teacher_id' => $teacherId,
                    'classe_id' => $classeId,
                ],
            ],
        ];
    }

    public function getMyTeachingForUser(User $user): array
    {
        $institution = $user->institution_id;
        if (! is_int($institution)) {
            return [];
        }

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        return $this->lire($this->visibles->bornees($institution)->where('created_by', $user->id), $dateDebut, $dateFin, $institution);
    }

    public function getMyClassesForUser(User $user): array
    {
        $institution = $user->institution_id;
        if (! is_int($institution) || ! $this->visibles->sesClasses($user, $institution)->exists()) {
            return ['classe_missing' => true];
        }

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        return $this->lire($this->visibles->deSesClasses($this->visibles->bornees($institution), $user, $institution), $dateDebut, $dateFin, $institution);
    }

    /**
     * Les séances de la fenêtre, triées par date puis par identifiant, dans la
     * forme du calendrier. Le nom des auteurs est lu en UNE requête.
     *
     * @param  Builder<Seance>  $seances
     * @return list<array<string, mixed>>
     */
    private function lire(Builder $seances, string $dateDebut, string $dateFin, int $institution): array
    {
        $lues = $seances
            ->withConnectedParticipantsCount()
            ->whereNotNull('date_seance')
            ->whereBetween('date_seance', [Carbon::parse($dateDebut)->startOfDay(), Carbon::parse($dateFin)->endOfDay()])
            ->orderBy('date_seance')
            ->orderBy($seances->qualifyColumn('id'))
            ->get();

        return $this->visibles->projeter($lues, $institution);
    }
}
