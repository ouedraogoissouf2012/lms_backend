<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Classe;
use App\Models\User;
use App\Models\UserClass;

/**
 * Résout les identifiants LOCAUX (`classes.id`) des classes d'un étudiant.
 *
 * ## Pourquoi ce collaborateur (issue #482)
 *
 * La classe d'un étudiant est stockée dans `UserClass.klassci_classe_id` — un
 * ID **KLASSCI** (source de vérité alimentée au login par
 * {@see \App\Services\Klassci\Auth\StudentClassSynchronizer}). La classe d'une
 * leçon est `lessons.classe_id` — un ID **LOCAL** (`classes.id`). Les deux clés
 * ne se joignent pas directement : le pont passe par
 * `classes.klassci_id ↔ UserClass.klassci_classe_id`.
 *
 * Ce résolveur centralise ce pont pour que `LessonListService::myCourses()` et
 * `::list()` restreignent identiquement un étudiant à SA classe (DRY, §1.6 D :
 * aucune Facade, tout via Eloquent injecté).
 */
final class StudentClasseResolver
{
    /**
     * Identifiants locaux (`classes.id`) des classes de l'étudiant, tenant-scopé.
     * Étudiant sans classe → tableau vide (fail-secure, REQ-4).
     *
     * Coût : 2 requêtes bornées (indépendantes du nombre de leçons), pas de N+1.
     *
     * @return list<int>
     */
    public function localClasseIdsFor(User $user): array
    {
        $klassciClasseIds = UserClass::query()
            ->where('user_id', $user->id)
            ->when(
                $user->institution_id !== null,
                fn ($query) => $query->where('institution_id', $user->institution_id)
            )
            ->pluck('klassci_classe_id');

        if ($klassciClasseIds->isEmpty()) {
            return [];
        }

        /** @var list<int> $localIds */
        $localIds = Classe::query()
            ->when(
                $user->institution_id !== null,
                fn ($query) => $query->where('institution_id', $user->institution_id)
            )
            ->whereIn('klassci_id', $klassciClasseIds)
            ->pluck('id')
            ->all();

        return $localIds;
    }
}
