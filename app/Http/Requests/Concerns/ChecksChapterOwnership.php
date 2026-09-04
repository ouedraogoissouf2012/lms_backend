<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Http\Requests\DeleteChapterRequest;
use App\Http\Requests\RestoreChapterRequest;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;

/**
 * Garde d'appartenance d'un chapitre, partagée entre suppression et restauration.
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
    /**
     * @param  bool  $includeTrashed  vrai pour la restauration, faux pour la suppression
     */
    protected function chapterOwnershipPasses(bool $includeTrashed = false): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if (! $user->isTeacher() && ! $user->isCoordinator() && ! $user->isAdmin()) {
            return false;
        }

        $query = Chapter::query()->where('id', $this->route('id'));

        if ($includeTrashed) {
            $query->withTrashed();
        }

        // Le cloisonnement repose sur ce filtre EXPLICITE, jamais sur le scope
        // global d'institution — celui-ci est fail-open quand aucun tenant
        // n'est résolu.
        $chapter = $query->where('institution_id', $user->institution_id)->first();

        if (! $chapter instanceof Chapter) {
            return false;
        }

        $lesson = Lesson::withTrashed()->find($chapter->lesson_id);

        if (! $lesson instanceof Lesson || $lesson->institution_id !== $user->institution_id) {
            return false;
        }

        // Un administrateur agit sur toute leçon de son institution ; les autres
        // rôles seulement sur la leur.
        return $user->isAdmin() || $lesson->enseignant_id === $user->id;
    }
}
