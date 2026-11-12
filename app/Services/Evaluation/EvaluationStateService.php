<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
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
 * - `422` — Aucune question (publish, et `refusSansQuestion` pour la mise à jour,
 *   C16) / déjà terminée (publish).
 * - `200` — OK.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationStateService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
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
        // C16 : la ligne est verrouillée, et les questions comptées sous ce
        // verrou. Un remplacement concurrent (update) attend la fin de la
        // publication, puis relit l'évaluation publiée sur la ligne.
        $refus = $this->db->transaction(function () use ($evaluation): ?array {
            $this->verrouiller($evaluation);
            $refus = $this->refusSansQuestion($evaluation->questions()->count())
                ?? ($evaluation->isTerminee() ? $this->refusTerminee() : null);
            if ($refus === null) {
                // Publication : visible aux étudiants, statut planifiée (= prête).
                $evaluation->update(['is_published' => true, 'status' => 'planifiee']);
            }

            return $refus;
        });
        if ($refus !== null) {
            return $refus;
        }

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

    /**
     * Verrouille la ligne de l'évaluation pour la transaction en cours, et la
     * rend relue en base (C16). Les deux voies qui touchent à l'invariant
     * (`publish`, et le remplacement des questions dans `update`) passent
     * par ce verrou : elles ne se chevauchent plus. Sans effet hors
     * transaction, et sans effet sous SQLite.
     */
    public function verrouiller(Evaluation $evaluation): ?Evaluation
    {
        return $evaluation->newQueryWithoutScopes()->whereKey($evaluation->getKey())->lockForUpdate()->first();
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function refusTerminee(): array
    {
        return [
            'status'  => 422,
            'payload' => [
                'success' => false,
                'message' => 'Impossible de publier: l\'évaluation est déjà terminée.',
            ],
        ];
    }

    /**
     * L'invariant « une évaluation publiée a au moins une question » (C16),
     * en un seul lieu : `publish` l'applique au compte actuel, `update` au
     * remplacement des questions d'une évaluation déjà publiée.
     *
     * @return array{status: int, payload: array<string, mixed>}|null
     */
    public function refusSansQuestion(int $questions): ?array
    {
        if ($questions > 0) {
            return null;
        }

        return [
            'status'  => 422,
            'payload' => [
                'success' => false,
                'message' => 'Une évaluation publiée doit compter au moins une question.',
                'reason'  => 'evaluation_without_questions',
            ],
        ];
    }
}
