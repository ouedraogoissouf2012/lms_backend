<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pivot local d'abord ; repli cache KLASSCI si le backfill n'a pas tourné.
 * Aucune conditionnelle de mode.
 *
 * La source d'une école KLASSCI seulement : une école autonome lit le pivot seul
 * ({@see EnrollmentSourceDeLEcole}, D410).
 */
final class CompositeEnrollmentSource implements EnrollmentSource
{
    public function __construct(
        private readonly LocalEnrollmentSource $local,
        private readonly KlassciEnrollmentSource $klassci,
    ) {
    }

    public function localClasseIdsFor(User $user): array
    {
        $fromPivot = $this->local->localClasseIdsFor($user);
        if ($fromPivot !== []) {
            return $fromPivot;
        }

        return $this->klassci->localClasseIdsFor($user);
    }

    /**
     * L'inverse exact du sens direct : le pivot actif de la classe, plus le repli
     * pour les seuls comptes qui n'ont AUCUNE ligne de pivot active, sur aucune
     * classe (c'est la condition du repli dans `localClasseIdsFor`).
     */
    public function localStudentIdsOf(Classe $classe): array
    {
        $parLePivot = $this->local->localStudentIdsOf($classe);
        $parLeRepli = $this->klassci->localStudentIdsOf($classe);

        if ($parLeRepli === []) {
            return $parLePivot;
        }

        $avecUneLigneActive = DB::table('classe_etudiant')
            ->whereIn('user_id', $parLeRepli)
            ->where('statut', 'actif')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $ids = array_values(array_unique([...$parLePivot, ...array_diff($parLeRepli, $avecUneLigneActive)]));
        sort($ids);

        return $ids;
    }

    public function classeIdsForTeacher(User $teacher): array
    {
        $fromLocal = $this->local->classeIdsForTeacher($teacher);
        if ($fromLocal !== []) {
            return $fromLocal;
        }

        return $this->klassci->classeIdsForTeacher($teacher);
    }
}
