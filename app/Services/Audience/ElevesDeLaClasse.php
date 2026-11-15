<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Classe;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Support\Collection;

/**
 * Les élèves d'une classe, à avertir : la SEULE règle, partagée par l'audience d'une séance
 * ({@see LocalClasseAudienceSource::studentsOf()}) et la publication d'une leçon
 * (`DispatchLessonPublishedNotifications`, D614).
 *
 * - Les inscrits de la classe par le port qui décide l'admission, dans son sens inverse
 *   (`EnrollmentSource::localStudentIdsOf`, C40) : un élève qui peut lire la leçon, ou entrer
 *   dans la séance, est averti, et réciproquement. La source suit l'autorité de l'école DE LA
 *   CLASSE : le pivot actif en école autonome ; le pivot actif puis le miroir `user_classes`
 *   en école KLASSCI. Aucun appel KLASSCI.
 * - Bornés explicitement à l'établissement : le scope multi-tenant est inerte dans un job, où
 *   les avertissements partent.
 * - Élèves au sens du prédicat de rôle (`isStudent()`), jamais un rôle écrit en dur.
 */
final class ElevesDeLaClasse
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    /**
     * Un établissement nul est comparé tel quel, comme l'audience d'une séance le faisait
     * avant l'extraction : celle-ci ne change aucun comportement.
     *
     * @return Collection<int, User>
     */
    public function de(Classe $classe, ?int $institutionId): Collection
    {
        return User::query()
            ->whereKey($this->inscriptions->localStudentIdsOf($classe))
            ->where('institution_id', $institutionId)
            ->orderBy('id')
            ->get()
            ->filter(static fn (User $eleve): bool => (is_numeric($eleve->institution_id) ? (int) $eleve->institution_id : null) === $institutionId
                && $eleve->isStudent())
            ->values();
    }
}
