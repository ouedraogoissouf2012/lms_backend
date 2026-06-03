<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mise à jour d'une évaluation existante : update des champs autorisés,
 * remplacement des questions associées (delete + recreate).
 *
 * Extrait de `EvaluationCrudController::update` (split §5).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte uniquement `LoggerInterface` (PSR-3). Aucune Facade
 * `Log::`, aucun `app()`. La transaction utilise `DB::beginTransaction()` —
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
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
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
        if (! $evaluation->canBeEdited()) {
            return [
                'status'  => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Impossible de modifier: des étudiants ont déjà soumis leurs réponses',
                ],
            ];
        }

        try {
            DB::beginTransaction();

            // Filtre les champs immuables (sécurité issue #124).
            $updatePayload = array_diff_key($data, array_flip(self::IMMUTABLE_FIELDS));
            $evaluation->update($updatePayload);

            // Remplacement complet des questions si fournies.
            if (array_key_exists('questions', $data) && is_array($data['questions'])) {
                $evaluation->questions()->delete();
                $this->createQuestions($evaluation, $data['questions']);
            }

            DB::commit();

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
            DB::rollBack();
            $this->logger->error('Erreur mise à jour évaluation', ['error' => $e->getMessage()]);

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
                'options'         => $this->decodeJsonField($questionData['options'] ?? null),
                'correct_answers' => $this->decodeJsonField($questionData['correct_answers'] ?? null),
                'explanation'     => $questionData['explanation'] ?? null,
            ]);
        }
    }

    /**
     * Décode un champ qui peut être un array natif ou une string JSON.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function decodeJsonField($value)
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $value;
    }
}
