<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Enums\LessonStatus;
use App\Jobs\DispatchLessonPublishedNotifications;
use App\Models\Lesson;
use App\Models\User;

/**
 * Write-side orchestration des leçons (split-15/lesson-crud).
 *
 * Extrait de `LessonCrudController` : centralise les 5 endpoints "mutation"
 * (`store`, `update`, `destroy`, `publish`, `unpublish`) — transitions de
 * statut, et fan-out notifications lors d'une publication initiale.
 *
 * ## Fan-out asynchrone (#538)
 *
 * Le fan-out des notifications de publication (résolution matière → classes →
 * étudiants via KLASSCI, puis création des notifications) est délégué au job
 * {@see DispatchLessonPublishedNotifications} : il faisait auparavant 1+N GET
 * HTTP KLASSCI + N INSERT **synchrones dans la requête**, bloquant le worker
 * PHP-FPM sur une grosse promo. Le service ne fait plus que dispatcher le job ;
 * il n'a donc plus besoin de `KlassciProxyService` ni de `LoggerInterface`.
 *
 * @see app/Http/Controllers/API/Lesson/LessonCrudController.php
 */
final class LessonCrudOperationsService
{
    /**
     * Créer une nouvelle leçon — `classe_id` et `matiere_id` sont des ids LOCAUX,
     * vérifiés par `StoreLessonRequest` (#873) — + auto-`published_at` si statut
     * `published`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): Lesson
    {
        $data['enseignant_id'] = $author->id;
        // Scope tenant explicite (defense en profondeur, fix E2E #211 flow 2).
        $data['institution_id'] = $author->institution_id;

        // Si la leçon est créée avec status "published", définir published_at automatiquement
        if (isset($data['status']) && $data['status'] === LessonStatus::Published->value && ! isset($data['published_at'])) {
            $data['published_at'] = now();
        }

        return Lesson::create($data);
    }

    /**
     * Met à jour une leçon — gère les transitions de statut et la
     * (dé)synchronisation de `published_at`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Lesson $lesson, array $data): Lesson
    {
        // Handle status transitions and published_at timestamp
        if (isset($data['status'])) {
            if ($data['status'] === LessonStatus::Published->value && ! $lesson->published_at) {
                $data['published_at'] = now();
            } elseif (in_array($data['status'], [LessonStatus::Draft->value, LessonStatus::Archived->value], true)) {
                $data['published_at'] = null;
            }
        }

        $lesson->update($data);

        return $lesson;
    }

    /**
     * Supprime une leçon (soft delete via `SoftDeletes`).
     */
    public function delete(Lesson $lesson): void
    {
        $lesson->delete();
    }

    /**
     * Publie une leçon. Si elle était en `draft`, fan-out des notifications aux
     * élèves inscrits dans SA classe (D614), quelle que soit sa matière.
     *
     * Les erreurs du fan-out sont logguées (warning) mais ne propagent pas :
     * la publication est considérée réussie même si le fan-out échoue.
     */
    public function publish(Lesson $lesson): Lesson
    {
        $wasUnpublished = $lesson->status === LessonStatus::Draft;
        $lesson->update([
            'status' => LessonStatus::Published,
            'published_at' => now(),
        ]);

        // Créer des notifications pour les étudiants concernés si le cours vient d'être publié
        if ($wasUnpublished) {
            $this->dispatchPublicationNotifications($lesson);
        }

        return $lesson;
    }

    /**
     * Dépublie une leçon (remise en brouillon, `published_at` réinitialisé).
     */
    public function unpublish(Lesson $lesson): Lesson
    {
        $lesson->update([
            'status' => LessonStatus::Draft,
            'published_at' => null,
        ]);

        return $lesson;
    }

    /**
     * Dispatche le fan-out ASYNCHRONE des notifications de publication (#538).
     *
     * Le travail (les élèves de la classe de la leçon, puis les notifications)
     * est effectué hors requête par {@see DispatchLessonPublishedNotifications},
     * qui repose le tenant de la leçon (le worker n'exécute pas le middleware
     * ResolveInstitution). C'est la CLASSE qui désigne les destinataires (D614) :
     * une leçon sans classe n'avertit personne, une leçon sans matière avertit sa
     * classe.
     */
    private function dispatchPublicationNotifications(Lesson $lesson): void
    {
        if ($lesson->classe_id === null || $lesson->institution_id === null) {
            return;
        }

        // #538 — la colonne réelle est `title` : l'ancien code lisait `$lesson->titre`
        // (attribut inexistant → null), d'où un titre VIDE dans la notification.
        DispatchLessonPublishedNotifications::dispatch(
            (int) $lesson->id,
            $lesson->matiere_id === null ? null : (int) $lesson->matiere_id,
            (string) $lesson->title,
            (int) $lesson->institution_id,
        );
    }
}
