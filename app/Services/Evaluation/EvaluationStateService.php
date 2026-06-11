<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use Psr\Log\LoggerInterface;

/**
 * Transitions d'état d'une évaluation : suppression (soft delete) et
 * publication.
 *
 * Extrait de `EvaluationCrudController::destroy` et `::publish` (split §5).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte uniquement `LoggerInterface` (PSR-3). Aucun `app()`,
 * aucune Facade `Log::`.
 *
 * ## Sémantique de retour
 *
 * Retourne `{status: int, payload: array}` — pas de couplage HTTP. Le caller
 * (controller) convertit en `JsonResponse`. Le `findOrFail` reste côté
 * controller pour préserver le rendu 404 par le handler Laravel
 * (`ModelNotFoundException` → 404) — comportement identique à l'original.
 *
 * - `403` — Soumissions existantes (destroy uniquement).
 * - `422` — Aucune question / déjà terminée (publish uniquement).
 * - `200` — OK.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationStateService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Soft delete d'une évaluation. Refuse si des soumissions étudiantes
     * existent déjà (évite la perte de données).
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function softDelete(Evaluation $evaluation, User $user): array
    {
        if (! $this->canBeEdited($evaluation)) {
            return [
                'status'  => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Impossible de supprimer: des étudiants ont déjà soumis leurs réponses',
                ],
            ];
        }

        $evaluation->delete();

        $this->logger->info('Évaluation supprimée', [
            'evaluation_id' => $evaluation->id,
            'user_id'       => $user->id,
        ]);

        return [
            'status'  => 200,
            'payload' => [
                'success' => true,
                'message' => 'Évaluation supprimée',
            ],
        ];
    }

    /**
     * Publie une évaluation. Pré-requis :
     *   - Au moins une question.
     *   - Évaluation non terminée.
     *
     * Délègue à `Evaluation::publish()` qui synchronise `status` +
     * `is_published` côté modèle.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function publish(Evaluation $evaluation, User $user): array
    {
        if ($evaluation->questions()->count() === 0) {
            return [
                'status'  => 422,
                'payload' => [
                    'success' => false,
                    'message' => 'Impossible de publier: l\'évaluation n\'a aucune question. Ajoutez des questions avant de publier.',
                ],
            ];
        }

        if ($evaluation->isTerminee()) {
            return [
                'status'  => 422,
                'payload' => [
                    'success' => false,
                    'message' => 'Impossible de publier: l\'évaluation est déjà terminée.',
                ],
            ];
        }

        // Publication : visible aux étudiants, statut planifiée (= prête).
        $evaluation->update([
            'is_published' => true,
            'status' => 'planifiee',
        ]);

        $this->logger->info('Évaluation publiée', [
            'evaluation_id' => $evaluation->id,
            'user_id'       => $user->id,
        ]);

        return [
            'status'  => 200,
            'payload' => [
                'success' => true,
                'message' => 'Évaluation publiée',
                'data'    => $evaluation->fresh(),
            ],
        ];
    }

    /**
     * L'évaluation est-elle verrouillée (flag explicite OU soumissions
     * étudiantes existantes) ? Ex-`Evaluation::isLocked` (H2 §5 — COUNT DB).
     */
    public function isLocked(Evaluation $evaluation): bool
    {
        return $evaluation->is_locked || $evaluation->submissions()->count() > 0;
    }

    /**
     * L'enseignant peut-il encore modifier l'évaluation ?
     */
    public function canBeEdited(Evaluation $evaluation): bool
    {
        return ! $this->isLocked($evaluation);
    }
}
