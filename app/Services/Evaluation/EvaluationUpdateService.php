<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Mise à jour d'une évaluation existante : update des champs autorisés,
 * remplacement des questions associées (delete + recreate).
 *
 * Extrait de `EvaluationCrudController::update` (split §5).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `DatabaseManager`, `EvaluationStateService` et
 * `CaughtExceptionLog` (journal des exceptions attrapées). Aucune Facade
 * `Log::`, aucun `app()`. La transaction utilise `$this->db->beginTransaction()` —
 * Facade autorisée car bas niveau Eloquent/transactional (cf. autres services
 * du projet).
 *
 * ## Sémantique de retour
 *
 * Retourne `{status: int, payload: array}` consommé par le controller pour
 * produire le `JsonResponse`. Pas de couplage HTTP dans le service.
 *
 * - `403` — Évaluation verrouillée (soumissions étudiantes existantes).
 * - `500` — Échec transactionnel (rollback + log).
 * - `422` — Le remplacement viderait une évaluation publiée (C16,
 *   `evaluation_without_questions`).
 * - `200` — Update OK, questions rechargées.
 *
 * ## Sécurité (issue #124)
 *
 * Les champs d'identité (`klassci_enseignant_id`, `institution_id`,
 * `klassci_*_id`) sont write-once post-create. Ils sont silencieusement
 * exclus du payload même si le client les envoie — empêche transfert
 * d'ownership ou mass-assignment via PUT.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationUpdateService
{
    /**
     * Champs immuables post-create. Silencieusement ignorés si présents
     * dans le payload (backward-compat).
     */
    private const IMMUTABLE_FIELDS = [
        'questions',
        'klassci_enseignant_id',
        'institution_id',
        'klassci_classe_id',
        'klassci_matiere_id',
        'klassci_evaluation_id',
        // D72 : les jumeaux locaux, champs d'identité eux aussi.
        'enseignant_id',
        'classe_id',
        'matiere_id',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EvaluationStateService $state,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Met à jour une évaluation et (optionnellement) remplace ses questions.
     *
     * @param  array<string, mixed>  $data  Payload validé par `UpdateEvaluationRequest`.
     *                                       Peut contenir `questions` (array) pour
     *                                       remplacement complet.
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function update(Evaluation $evaluation, array $data, User $user): array
    {
        if (! $this->state->canBeEdited($evaluation)) {
            return $this->refusVerrouillee();
        }

        $questions = array_key_exists('questions', $data) && is_array($data['questions']) ? $data['questions'] : null;

        try {
            $this->db->beginTransaction();

            // C16 : le refus annule la transaction avant toute écriture.
            $sansQuestion = $this->refusSurLaLigneVerrouillee($evaluation, $questions);
            if ($sansQuestion !== null) {
                $this->db->rollBack();

                return $sansQuestion;
            }

            // Filtre les champs immuables (sécurité issue #124).
            $updatePayload = array_diff_key($data, array_flip(self::IMMUTABLE_FIELDS));
            $evaluation->update($updatePayload);

            // Remplacement complet des questions si fournies.
            if ($questions !== null) {
                $evaluation->questions()->delete();
                $this->createQuestions($evaluation, $questions);
            }

            $this->db->commit();

            $evaluation->load('questions');

            return [
                'status'  => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Évaluation mise à jour',
                    'data'    => $evaluation,
                ],
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->journal->error('Erreur mise à jour évaluation', $e);

            return [
                'status'  => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la mise à jour',
                    'error'   => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function refusVerrouillee(): array
    {
        return [
            'status'  => 403,
            'payload' => [
                'success' => false,
                'message' => 'Impossible de modifier: des étudiants ont déjà soumis leurs réponses',
            ],
        ];
    }

    /**
     * C16 : une évaluation publiée garde au moins une question. La ligne est
     * verrouillée EN PREMIER dans la transaction, et « publiée » est relu sur
     * elle, pas sur le modèle chargé avant : une publication concurrente est
     * vue (`EvaluationStateService::verrouiller`, le même verrou que
     * `publish`).
     *
     * @param  array<mixed>|null  $questions  Le remplacement demandé, ou `null`.
     * @return array{status: int, payload: array<string, mixed>}|null
     */
    private function refusSurLaLigneVerrouillee(Evaluation $evaluation, ?array $questions): ?array
    {
        if ($questions === null || ! $this->state->verrouiller($evaluation)?->is_published) {
            return null;
        }

        return $this->state->refusSansQuestion(count($questions));
    }

    /**
     * Persiste les questions associées à une évaluation. `options` et
     * `correct_answers` peuvent arriver sous forme de string JSON
     * (multipart/form-data) — décodés ici pour rester compatibles avec
     * les deux formats.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function createQuestions(Evaluation $evaluation, array $questions): void
    {
        foreach ($questions as $index => $questionData) {
            EvaluationQuestion::create([
                'evaluation_id'   => $evaluation->id,
                'question'        => $questionData['question'],
                'type'            => $questionData['type'],
                'ordre'           => $index + 1,
                'points'          => $questionData['points'] ?? 1,
                'options'         => $questionData['options'] ?? null,
                'correct_answers' => $questionData['correct_answers'] ?? null,
                'explanation'     => $questionData['explanation'] ?? null,
            ]);
        }
    }
}
