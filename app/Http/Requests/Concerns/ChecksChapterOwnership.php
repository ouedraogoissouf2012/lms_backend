<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Http\Requests\DeleteChapterRequest;
use App\Http\Requests\RestoreChapterRequest;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Search\TeacherOwnershipScope;

/**
 * Garde d'appartenance d'un chapitre, partagée entre suppression, restauration et
 * dépôt d'un fichier (`UploadChapterFileRequest`) : l'enseignant auteur de la leçon,
 * et lui seul ; la gestion consulte, et son refus porte un motif
 * ({@see ReservesLessonContentToTeacher}, #1167).
 *
 * ## Pourquoi un trait plutôt qu'une seconde copie
 *
 * La restauration remet du contenu en ligne : elle ne peut pas être moins gardée
 * que la suppression. Recopier la règle laisserait les deux dériver — et c'est
 * l'écart entre les deux qui serait la faille, pas la règle elle-même.
 *
 * ## Le seul paramètre : la corbeille
 *
 * Supprimer s'applique à un chapitre visible ; restaurer s'applique à un
 * chapitre **déjà à la corbeille**. Sans `withTrashed()`, la recherche ne
 * trouverait rien et la restauration serait refusée en 403 — un refus qui
 * ressemblerait à un problème de droits alors qu'il s'agirait d'une requête
 * mal construite.
 *
 * @see DeleteChapterRequest
 * @see RestoreChapterRequest
 */
trait ChecksChapterOwnership
{
    use ReservesLessonContentToTeacher;

    /**
     * @param  TeacherOwnershipScope  $auteurs  la source unique de l'auteur d'une leçon
     * @param  bool  $includeTrashed  vrai pour la restauration, faux pour la suppression
     * @param  string  $parametre  le paramètre de route qui porte l'id du chapitre
     */
    protected function chapterOwnershipPasses(TeacherOwnershipScope $auteurs, bool $includeTrashed = false, string $parametre = 'id'): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $chapter = $this->chapitreDeLEtablissement($user, $includeTrashed, $parametre);

        if (! $chapter instanceof Chapter) {
            return false;
        }

        $lesson = Lesson::withTrashed()->find($chapter->lesson_id);

        if (! $lesson instanceof Lesson || $lesson->institution_id !== $user->institution_id) {
            return false;
        }

        // L'enseignant auteur de la leçon, et lui seul (#1167) : la gestion consulte.
        return $this->enseignantAuteur($lesson, $user, $auteurs);
    }

    /**
     * Le chapitre désigné par la route, dans l'établissement du compte, ou `null`.
     * Le cloisonnement repose sur ce filtre EXPLICITE, jamais sur le scope global
     * d'institution — celui-ci est fail-open quand aucun tenant n'est résolu.
     */
    protected function chapitreDeLEtablissement(User $user, bool $includeTrashed, string $parametre): ?Chapter
    {
        $query = Chapter::query()->where('id', $this->route($parametre));

        if ($includeTrashed) {
            $query->withTrashed();
        }

        $chapter = $query->where('institution_id', $user->institution_id)->first();

        return $chapter instanceof Chapter ? $chapter : null;
    }
}
