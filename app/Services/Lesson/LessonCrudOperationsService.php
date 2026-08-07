<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Notification;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * Write-side orchestration des leçons (split-15/lesson-crud).
 *
 * Extrait de `LessonCrudController` : centralise les 5 endpoints "mutation"
 * (`store`, `update`, `destroy`, `publish`, `unpublish`) — résolution
 * matière cross-source, transitions de statut, et fan-out notifications
 * lors d'une publication initiale.
 *
 * ## DI strict (§1.6 D)
 *
 * Dépendances injectées par constructeur (`KlassciProxyService` +
 * `LoggerInterface`). Aucun appel à `app()` ni à la Facade `Log`.
 *
 * ## Comportement
 *
 * Aucun changement runtime : logique déplacée verbatim depuis le
 * god-controller. La Facade `\Log::warning` est remplacée par
 * `LoggerInterface::warning` (PSR-3) — strictement équivalent.
 *
 * @see app/Http/Controllers/API/Lesson/LessonCrudController.php
 */
final class LessonCrudOperationsService
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Créer une nouvelle leçon — résolution `matiere_id` (id local OU
     * `klassci_id`) + auto-`published_at` si statut `published`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): Lesson
    {
        $data['enseignant_id'] = $author->id;
        // Scope tenant explicite (defense en profondeur, fix E2E #211 flow 2).
        $data['institution_id'] = $author->institution_id;

        // Résoudre matiere_id : le frontend peut envoyer un KLASSCI ID
        if (isset($data['matiere_id'])) {
            $matiere = Matiere::find($data['matiere_id']);
            if (!$matiere) {
                // Chercher par klassci_id
                $matiere = Matiere::where('klassci_id', $data['matiere_id'])->first();
                if ($matiere) {
                    $data['matiere_id'] = $matiere->id;
                }
            }
        }

        // #265 — Résoudre classe_id : le frontend envoie un id KLASSCI de classe
        // (comme matiere_id). On préfère l'id local, sinon on traduit le
        // klassci_id → id local pour conserver la relation Lesson->Classe (FK
        // local). Scopé à l'institution de l'auteur (isolation multi-tenant :
        // le klassci_id n'est unique que par institution).
        if (isset($data['classe_id'])) {
            $classe = Classe::where('institution_id', $author->institution_id)
                ->where('id', $data['classe_id'])
                ->first();

            if (!$classe) {
                $classe = Classe::where('institution_id', $author->institution_id)
                    ->where('klassci_id', $data['classe_id'])
                    ->first();
            }

            if ($classe) {
                $data['classe_id'] = $classe->id;
            }
        }

        // Si la leçon est créée avec status "published", définir published_at automatiquement
        if (isset($data['status']) && $data['status'] === LessonStatus::Published->value && !isset($data['published_at'])) {
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
            if ($data['status'] === LessonStatus::Published->value && !$lesson->published_at) {
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
     * Publie une leçon. Si elle était en `draft`, fan-out notifications
     * aux étudiants des classes liées à la matière via KLASSCI.
     *
     * Les erreurs KLASSCI sont logguées (warning) mais ne propagent pas :
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
        if ($wasUnpublished && $lesson->matiere_id) {
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
     * Récupère via KLASSCI la liste des étudiants concernés par la matière
     * de la leçon (matière → classes → étudiants) et crée une notification
     * par étudiant local correspondant (`klassci_id` mappé).
     *
     * Toute exception KLASSCI est isolée et logguée — la publication
     * elle-même n'est pas annulée.
     */
    private function dispatchPublicationNotifications(Lesson $lesson): void
    {
        try {
            $matiereData = $this->klassci->get("/matieres/{$lesson->matiere_id}");

            if (isset($matiereData['data']['classe_ids']) && is_array($matiereData['data']['classe_ids'])) {
                $studentIds = [];
                foreach ($matiereData['data']['classe_ids'] as $classeId) {
                    $classeData = $this->klassci->get("/classes/{$classeId}");
                    if (isset($classeData['data']['etudiant_ids']) && is_array($classeData['data']['etudiant_ids'])) {
                        $studentIds = array_merge($studentIds, $classeData['data']['etudiant_ids']);
                    }
                }

                // Créer notification pour chaque étudiant
                $studentIds = array_unique($studentIds);
                foreach ($studentIds as $klassciId) {
                    // Trouver l'utilisateur local correspondant
                    $student = User::where('klassci_id', $klassciId)->first();
                    if ($student) {
                        Notification::create([
                            'user_id' => $student->id,
                            'type' => Notification::TYPE_LESSON_PUBLISHED,
                            'title' => 'Nouveau cours disponible',
                            'message' => 'Un nouveau cours "' . $lesson->titre . '" est maintenant disponible',
                            'data' => [
                                'lesson_id' => $lesson->id,
                                'matiere_id' => $lesson->matiere_id,
                            ],
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Si erreur KLASSCI, on continue quand même
            $this->logger->warning('Erreur lors de la création des notifications de cours: ' . $e->getMessage());
        }
    }
}
