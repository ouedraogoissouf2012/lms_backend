<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quelles leçons un compte lit : SOURCE UNIQUE de la portée de lecture (#942).
 *
 * Deux axes, et rien d'autre : ni chargement, ni filtre de requête, ni
 * pagination, qui restent à l'appelant.
 *
 * ## Le tenant
 *
 * - Un compte rattaché à un établissement ne lit que le sien. Le filtre est
 *   explicite : le scope global `BelongsToInstitution` est ouvert par défaut
 *   quand aucun tenant n'est posé (fix E2E #211 flow 5).
 * - Sans établissement, seul le supradmin plateforme
 *   ({@see User::isPlatformSupradmin()}, comparaison stricte, #511) est
 *   cross-tenant, par conception. Tout autre compte sans établissement ne lit
 *   RIEN : c'est le `null` absorbant d'ADR-792-01, appliqué à la lecture des
 *   leçons. `ResolveInstitution` ne pose aucun tenant pour un tel compte (#972).
 *
 * ## L'audience
 *
 * Hors personnel reconnu ({@see User::isLearnerAudience()}, #941) : les leçons
 * publiées de SES classes, par le composite (#482).
 */
final class LessonReadScope
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    /**
     * Restreint une requête de liste à ce que le compte lit.
     *
     * @param  Builder<Lesson>  $query
     */
    public function apply(Builder $query, User $user): void
    {
        if ($user->institution_id !== null) {
            $query->where($query->qualifyColumn('institution_id'), $user->institution_id);
        } elseif (! $user->isPlatformSupradmin()) {
            // Contradiction volontaire, sans `IS NULL` qui remonterait les
            // leçons orphelines (même idiome que TeacherOwnershipScope).
            $query->whereRaw('1 = 0');
        }

        if ($user->isLearnerAudience()) {
            $query->published()
                ->whereIn($query->qualifyColumn('classe_id'), $this->inscriptions->localClasseIdsFor($user));
        }
    }

    /**
     * Refus de lecture d'UNE leçon, dans la forme que rend déjà
     * LessonListService::show (`{error, status}`), ou null si la lecture est
     * permise.
     *
     * Hors de son établissement, ou sans établissement hors supradmin : 404,
     * qui ne révèle pas l'existence de la leçon. Une leçon non publiée, pour
     * un apprenant : 403, comme jusqu'ici.
     *
     * @return array{error: string, status: int}|null
     */
    public function denies(Lesson $lesson, User $user): ?array
    {
        $horsTenant = $user->institution_id !== null
            ? $lesson->institution_id !== $user->institution_id
            : ! $user->isPlatformSupradmin();

        if ($horsTenant) {
            return ['error' => 'Cours non trouvé', 'status' => 404];
        }

        if ($user->isLearnerAudience() && ! $lesson->isPublished()) {
            return ['error' => 'Ce cours n\'est pas encore disponible', 'status' => 403];
        }

        return null;
    }
}
