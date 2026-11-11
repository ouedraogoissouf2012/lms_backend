<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\Classe\ClassesAtteintes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les listes de séances d'une école créée depuis le LMS, ou basculée
 * (ADR-EI-01, lot 3d). Aucun jeton, aucun appel à KLASSCI.
 *
 * ## Qui voit quoi (D119)
 *
 * | Appelant | Séances lues |
 * |---|---|
 * | gestion (`isManager()`) | toutes celles de l'école |
 * | enseignant | les siennes : `created_by`, la règle de l'historique |
 * | apprenant, et tout rôle non reconnu (#941) | celles de ses classes à inscription ACTIVE (D57), moins celles qu'il a masquées |
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
        private readonly SeanceCalendarProjection $projection,
    ) {}

    public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId): array
    {
        $dateDebut = Carbon::now()->format('Y-m-d');
        $dateFin = Carbon::now()->addDays($days)->format('Y-m-d');
        $institution = $user->institution_id;

        $seances = [];
        if (is_int($institution)) {
            $visibles = $this->visibles($user, $institution);
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

        return $this->lire($this->bornees($institution)->where('created_by', $user->id), $dateDebut, $dateFin, $institution);
    }

    public function getMyClassesForUser(User $user): array
    {
        $institution = $user->institution_id;
        if (! is_int($institution) || ! $this->sesClasses($user, $institution)->exists()) {
            return ['classe_missing' => true];
        }

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        return $this->lire($this->deSesClasses($this->bornees($institution), $user, $institution), $dateDebut, $dateFin, $institution);
    }

    /** @return Builder<Seance> */
    private function visibles(User $user, int $institution): Builder
    {
        $seances = $this->bornees($institution);

        if ($user->isManager()) {
            return $seances;
        }

        if ($user->isTeacher()) {
            return $seances->where('created_by', $user->id);
        }

        return $this->deSesClasses($seances, $user, $institution);
    }

    /** @return Builder<Seance> */
    private function bornees(int $institution): Builder
    {
        return Seance::query()->where('institution_id', $institution)->where('is_active', true);
    }

    /**
     * @param  Builder<Seance>  $seances
     * @return Builder<Seance>
     */
    private function deSesClasses(Builder $seances, User $user, int $institution): Builder
    {
        return $seances
            ->whereIn('classe_id', $this->sesClasses($user, $institution)->select('classes.id'))
            ->whereNotIn($seances->qualifyColumn('id'), SeanceUserHidden::query()->where('user_id', $user->id)->select('seance_id'));
    }

    /** @return Builder<Classe> */
    private function sesClasses(User $user, int $institution): Builder
    {
        return ClassesAtteintes::inscrites(Classe::query()->where('institution_id', $institution), $user);
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

        $auteurs = User::query()
            ->where('institution_id', $institution)
            ->whereIn('id', $lues->pluck('created_by')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        return array_values($lues->map(function (Seance $seance) use ($auteurs): array {
            $auteur = $seance->created_by !== null ? $auteurs->get($seance->created_by) : null;

            return $this->projection->project($seance, SeanceIdentite::locale($seance, is_string($auteur) ? $auteur : null));
        })->all());
    }
}
