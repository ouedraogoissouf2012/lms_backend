<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\Classe\ClassesAtteintes;
use App\Services\Enrollment\InscriptionNonActive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les séances qu'un compte VOIT dans une école créée depuis le LMS, et leur forme
 * de calendrier : la règle UNIQUE de la liste (lot 3d) et du détail. Le détail ne
 * montre ainsi jamais ce que la liste cache, ni l'inverse.
 *
 * ## Qui voit quoi (D119)
 *
 * | Appelant | Séances lues |
 * |---|---|
 * | gestion (`isManager()`) | toutes celles de l'école |
 * | enseignant | les siennes : `created_by`, la règle de l'historique |
 * | apprenant, et tout rôle non reconnu (#941) | celles de ses classes à inscription ACTIVE (D57), moins celles qu'il a masquées |
 *
 * Toutes les lectures sont bornées EXPLICITEMENT à l'établissement : le scope
 * global est fail-open sans tenant.
 *
 * Extrait de {@see LocalSeancesListReader}, sans changement de comportement.
 */
final class SeancesLocalesVisibles
{
    public function __construct(
        private readonly SeanceCalendarProjection $projection,
    ) {}

    /** @return Builder<Seance> */
    public function pour(User $user, int $institution): Builder
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
    public function bornees(int $institution): Builder
    {
        return Seance::query()->where('institution_id', $institution)->where('is_active', true);
    }

    /**
     * @param  Builder<Seance>  $seances
     * @return Builder<Seance>
     */
    public function deSesClasses(Builder $seances, User $user, int $institution): Builder
    {
        return self::parClasses($seances, $this->sesClasses($user, $institution), $user);
    }

    /**
     * Les séances qu'une inscription ACTIVE lui ouvrirait, là où sa ligne n'est pas
     * active (D57) : la règle de l'apprenant, rejouée sur ses lignes non actives. Le
     * détail en tire son 409 `enrolment_not_active` (#1095) ; rien n'y est lu.
     *
     * @return Builder<Seance>
     */
    public function deSesInscriptionsNonActives(User $user, int $institution): Builder
    {
        return self::parClasses(
            $this->bornees($institution),
            InscriptionNonActive::classes(Classe::query()->where('institution_id', $institution), $user),
            $user,
        );
    }

    /**
     * @param  Builder<Seance>  $seances
     * @param  Builder<Classe>  $classes
     * @return Builder<Seance>
     */
    private static function parClasses(Builder $seances, Builder $classes, User $user): Builder
    {
        return $seances
            ->whereIn('classe_id', $classes->select('classes.id'))
            ->whereNotIn($seances->qualifyColumn('id'), SeanceUserHidden::query()->where('user_id', $user->id)->select('seance_id'));
    }

    /** @return Builder<Classe> */
    public function sesClasses(User $user, int $institution): Builder
    {
        return ClassesAtteintes::inscrites(Classe::query()->where('institution_id', $institution), $user);
    }

    /**
     * Les séances lues, dans la forme du calendrier. Le nom des auteurs, les
     * classes et les matières sont lus en une requête chacun.
     *
     * @param  Collection<int, Seance>  $lues
     * @return list<array<string, mixed>>
     */
    public function projeter(Collection $lues, int $institution): array
    {
        $auteurs = User::query()
            ->where('institution_id', $institution)
            ->whereIn('id', $lues->pluck('created_by')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $classes = $this->classesDe($lues->pluck('classe_id')->filter()->unique()->values()->all(), $institution);
        $matieres = $this->matieresDe($lues->pluck('matiere_id')->filter()->unique()->values()->all(), $institution);

        return array_values($lues->map(function (Seance $seance) use ($auteurs, $classes, $matieres): array {
            $auteur = $seance->created_by !== null ? $auteurs->get($seance->created_by) : null;
            $classe = $seance->classe_id !== null ? ($classes[$seance->classe_id] ?? null) : null;
            $matiere = $seance->matiere_id !== null ? ($matieres[$seance->matiere_id] ?? null) : null;

            return $this->projection->project($seance, SeanceIdentite::locale($seance, is_string($auteur) ? $auteur : null, $classe, $matiere));
        })->all());
    }

    /**
     * Le libellé actuel des matières des séances lues, dans l'établissement.
     * Une requête pour toutes.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function matieresDe(array $ids, int $institution): array
    {
        if ($ids === []) {
            return [];
        }

        $matieres = [];
        foreach (Matiere::query()->where('institution_id', $institution)->whereIn('id', $ids)->get(['id', 'libelle']) as $matiere) {
            $matieres[$matiere->id] = $matiere->libelle;
        }

        return $matieres;
    }

    /**
     * Les classes des séances lues, dans l'établissement : leur libellé et leurs
     * inscriptions actives d'élèves de l'établissement, comme `/proxy/classes`.
     * Une requête pour toutes.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array{nom: string, effectif: int}>
     */
    private function classesDe(array $ids, int $institution): array
    {
        if ($ids === []) {
            return [];
        }

        $classes = [];
        foreach (Classe::query()
            ->where('institution_id', $institution)
            ->whereIn('id', $ids)
            ->withCount(['etudiantsActifs as effectif' => static function ($inscrits) use ($institution): void {
                $inscrits->whereIn('users.id', User::query()->where('institution_id', $institution)->select('id'));
            }])
            ->get() as $classe) {
            $effectif = $classe->getAttribute('effectif');
            $classes[$classe->id] = ['nom' => $classe->libelle, 'effectif' => is_numeric($effectif) ? (int) $effectif : 0];
        }

        return $classes;
    }
}
