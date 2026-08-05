<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Lesson;

/**
 * Garantit l'invariant #481 : `status='published' ⇒ published_at != null`, et
 * sa réciproque `status ∈ {draft, archived, …} ⇒ published_at = null`.
 *
 * ## Pourquoi (défense en profondeur, §1.1)
 *
 * Le scope {@see Lesson::scopePublished()} exige `published_at IS NOT NULL` pour
 * qu'une leçon soit visible. Les 4 chemins de
 * {@see \App\Services\Lesson\LessonCrudOperationsService} (create/update/publish/
 * unpublish) posent déjà `published_at` correctement — mais une écriture qui les
 * contourne (factory, seeder, import, tinker, `Lesson::create()` direct) pouvait
 * créer une leçon `published` avec `published_at = NULL`, donc invisible.
 *
 * Cet observer capture l'événement `saving` (avant tout INSERT/UPDATE) et corrige
 * l'attribut EN MÉMOIRE sur l'instance : aucun `save()` interne, donc aucune
 * récursion d'événements.
 */
final class LessonObserver
{
    public function saving(Lesson $lesson): void
    {
        if ($lesson->status === 'published') {
            // Ne jamais écraser une date de publication déjà posée (#481 REQ-3).
            if ($lesson->published_at === null) {
                $lesson->published_at = now();
            }

            return;
        }

        // Tout statut non publié (draft/archived) : pas de date de publication.
        $lesson->published_at = null;
    }
}
