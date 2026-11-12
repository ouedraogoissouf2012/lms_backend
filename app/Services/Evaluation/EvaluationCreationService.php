<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\Evaluation\Programming\ProgrammedEvaluation;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Création d'une évaluation : insertion en base et persistance des questions
 * associées.
 *
 * Extrait de `EvaluationCrudController::store` (split §5). Le rattachement
 * (classe, matière, libellés) est établi AVANT, par la source de programmation
 * de l'école (`EvaluationProgramming`, D72) : ce service n'interroge plus
 * KLASSCI.
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `DatabaseManager` et `CaughtExceptionLog` (journal
 * fail-soft des exceptions attrapées). Aucun `app()`, aucune Facade `Log::`.
 *
 * ## Sémantique de retour
 *
 * Retourne un array `{status: int, payload: array}` consommé par le controller
 * pour produire le `JsonResponse`. Pas de couplage HTTP dans le service.
 *
 * - `409` — Une évaluation LMS existe déjà pour `klassci_evaluation_id`.
 * - `500` — Échec transactionnel (rollback + log).
 * - `201` — Création OK avec l'évaluation et ses questions chargées.
 *
 * ## Sécurité (issue #124)
 *
 * L'auteur (`enseignant_id`, et `klassci_enseignant_id` dans une école créée
 * depuis KLASSCI) est dérivé du token Sanctum, passé via `$teacher` et la
 * programmation. Jamais lu du body utilisateur — empêche un enseignant de
 * créer une évaluation au nom d'un collègue (mass-assignment).
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationCreationService
{
    /** Champs autorisés depuis le payload utilisateur (whitelist). */
    private const FILLABLE_FROM_REQUEST = [
        'titre',
        'description',
        'type',
        'status',
        'date_evaluation',
        'duree_minutes',
        'coefficient',
        'bareme',
        'is_online',
        'allow_retake',
        'max_attempts',
        'shuffle_questions',
        'show_results',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Crée une évaluation et ses questions (transactionnel).
     *
     * @param  array<string, mixed>  $data  Payload validé par `StoreEvaluationRequest`.
     *                                       Peut contenir une clé `questions` (array).
     * @param  ProgrammedEvaluation  $programme  Rattachement établi par la source de l'école.
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function create(array $data, User $teacher, ProgrammedEvaluation $programme): array
    {
        // Conflit : une évaluation LMS existe déjà pour cette KLASSCI evaluation.
        $klassciEvaluationId = $programme->attributs['klassci_evaluation_id'] ?? null;
        if ($klassciEvaluationId) {
            $existing = Evaluation::where('klassci_evaluation_id', $klassciEvaluationId)->first();
            if ($existing) {
                return $this->conflict($existing);
            }
        }

        try {
            $this->db->beginTransaction();

            $evaluation = Evaluation::create(array_merge(
                array_intersect_key($data, array_flip(self::FILLABLE_FROM_REQUEST)),
                $programme->attributs,
                [
                    // L'auteur, dans les deux types d'école (D72) : celui que
                    // la source établit (le formateur désigné, D136), sinon
                    // l'appelant.
                    'enseignant_id'  => $programme->attributs['enseignant_id'] ?? $teacher->getKey(),
                    // Scope tenant explicite (fix E2E #211 flow 2).
                    'institution_id' => $teacher->institution_id,
                ],
            ));

            if (! empty($data['questions']) && is_array($data['questions'])) {
                $this->createQuestions($evaluation, $data['questions']);
            }

            $this->db->commit();

            $evaluation->load('questions');

            return [
                'status'  => 201,
                'payload' => [
                    'success' => true,
                    'message' => 'Évaluation créée avec succès',
                    'data'    => $evaluation,
                ],
            ];
        } catch (UniqueConstraintViolationException) {
            // Course perdue : le garde ci-dessus (SELECT hors transaction) a été
            // franchi par deux requêtes simultanées, et c'est désormais l'index
            // `evaluations_klassci_link_unique` (#541) qui tranche. Le résultat
            // métier est le MÊME conflit — on renvoie donc le 409 déjà défini par
            // cet endpoint, pas un 500 opaque.
            $this->db->rollBack();

            return $this->conflict(
                Evaluation::where('klassci_evaluation_id', $klassciEvaluationId)->first(),
            );
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->journal->error('Erreur création évaluation', $e);

            return [
                'status'  => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la création de l\'évaluation',
                    'error'   => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Réponse 409 « une version en ligne existe déjà », qu'elle vienne du garde
     * applicatif ou du rejet de l'index unique sur course perdue.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function conflict(?Evaluation $existing): array
    {
        return [
            'status'  => 409,
            'payload' => [
                'success' => false,
                'message' => 'Une version en ligne existe déjà pour cette évaluation KLASSCI.',
                'data'    => $existing,
            ],
        ];
    }

    /**
     * Persiste les questions associées à une évaluation. Les champs `options`
     * et `correct_answers` peuvent arriver sous forme de string JSON
     * (multipart/form-data) — décodés ici pour rester compatibles avec les
     * deux formats (array natif via JSON body, string via form-data).
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
     * Retourne `$value` inchangée si déjà array, ou si le décodage échoue.
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
