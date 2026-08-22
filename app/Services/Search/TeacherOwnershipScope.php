<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restreint une requête aux ressources appartenant à un enseignant (#575).
 *
 * Cette classe existe pour qu'il n'y ait **qu'un seul endroit** où soit écrit
 * « quelle colonne identifie l'enseignant propriétaire, et quelle valeur de
 * l'utilisateur y comparer ». La règle était auparavant recopiée à la main dans
 * quatre requêtes ({@see GlobalSearchService}, {@see SearchSuggestionsService}),
 * toutes les quatre sur une colonne `teacher_id` qui n'existe dans aucune
 * migration — la duplication est ce qui a permis au défaut d'exister en quatre
 * exemplaires et d'y rester.
 *
 * ## Décision d'identité (critère de fermeture de #575)
 *
 * Les deux modèles ne se ressemblent pas, et le commentaire de migration ment
 * pour l'un des deux :
 *
 * | Modèle | Colonne | Valeur comparée | Autorité |
 * |---|---|---|---|
 * | `Lesson` | `enseignant_id` | `$teacher->id` (id LOCAL) | écrivain `LessonCrudOperationsService.php:43` |
 * | `Evaluation` | `klassci_enseignant_id` | `$teacher->klassci_enseignant_id` | écrivain `EvaluationCreationService.php:110` |
 *
 * - **`lessons.enseignant_id`** est commentée « ID enseignant KLASSCI » dans sa
 *   migration (`2025_10_14_160000_create_lessons_table.php:20`) : c'est faux.
 *   Le seul écrivain applicatif y met `$author->id`, la relation Eloquent est un
 *   `belongsTo(User::class, 'enseignant_id')` ({@see \App\Models\Lesson}), et les
 *   sept contrôles d'autorisation du domaine leçon/chapitre comparent tous à
 *   `$user->id` (`DeleteLessonRequest.php:49`, `UpdateLessonRequest.php:58`,
 *   `PublishLessonRequest.php:52`, `UnpublishLessonRequest.php:51`,
 *   `UpdateChapterRequest.php:54`, `DeleteChapterRequest.php:49`,
 *   `ReorderChaptersRequest.php:53`). Aucun chemin d'import n'y écrit d'id KLASSCI.
 *   On ne matche donc PAS aussi `klassci_id` « par tolérance » : ce serait ouvrir
 *   une fuite par collision d'identifiants pour couvrir un cas qui n'existe pas.
 *
 * - **`evaluations.klassci_enseignant_id`** porte bien l'identité KLASSCI
 *   enseignant, colonne d'autorité write-once désignée par #119 et déjà utilisée
 *   comme telle par `ChecksEvaluationOwnership.php:99-101`.
 *
 * ## Fermeture par défaut sur identité KLASSCI absente
 *
 * `Illuminate\Database\Query\Builder::where()` réécrit `where($col, null)` en
 * `whereNull($col)`. Pour un enseignant sans `klassci_enseignant_id`, le filtre
 * naïf remonterait donc TOUTES les évaluations orphelines du tenant — pire que
 * le bug corrigé. Un tel utilisateur ne peut posséder aucune évaluation
 * (`EvaluationCrudController.php:84` refuse la création sans cette identité), on
 * ferme donc explicitement.
 *
 * ## Périmètre (SRP §1.6)
 *
 * Ce collaborateur ne décide PAS *qui* est soumis au filtre : le dispatch par
 * rôle (`isTeacher()` / `isStudent()`) est une règle métier de la recherche et
 * reste chez les appelants.
 *
 * @see .claude/specs/575-search-teacher-id/design.md §1
 */
final class TeacherOwnershipScope
{
    /**
     * Restreindre aux leçons dont `$teacher` est l'auteur.
     *
     * @param  Builder<\App\Models\Lesson>  $query
     */
    public function applyToLessons(Builder $query, User $teacher): void
    {
        $query->where('enseignant_id', $teacher->id);
    }

    /**
     * Restreindre aux évaluations dont `$teacher` est propriétaire — aucune
     * si son identité KLASSCI enseignant n'est pas établie.
     *
     * @param  Builder<\App\Models\Evaluation>  $query
     */
    public function applyToEvaluations(Builder $query, User $teacher): void
    {
        $klassciEnseignantId = $teacher->klassci_enseignant_id;

        if ($klassciEnseignantId === null) {
            // Contradiction volontaire : ferme la requête sans émettre de
            // `IS NULL`, qui remonterait les évaluations orphelines.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('klassci_enseignant_id', $klassciEnseignantId);
    }
}
