<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Création d'une évaluation : insertion en base, lookup des libellés
 * matière/classe via KLASSCI, persistance des questions associées.
 *
 * Extrait de `EvaluationCrudController::store` (split §5).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService` (lookup matière/classe) et
 * `LoggerInterface` PSR-3 (logging fail-soft). Aucun `app()`, aucune Facade
 * `Log::`.
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
 * `klassci_enseignant_id` est dérivé du token Sanctum côté caller et passé
 * via `$teacher`. Jamais lu du body utilisateur — empêche un enseignant de
 * créer une évaluation au nom d'un collègue (mass-assignment).
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationCreationService
{
    /** Champs autorisés depuis le payload utilisateur (whitelist). */
    private const FILLABLE_FROM_REQUEST = [
        'klassci_matiere_id',
        'klassci_classe_id',
        'klassci_evaluation_id',
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
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Crée une évaluation et ses questions (transactionnel).
     *
     * @param  array<string, mixed>  $data  Payload validé par `StoreEvaluationRequest`.
     *                                       Peut contenir une clé `questions` (array).
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function create(array $data, User $teacher): array
    {
        // Conflit : une évaluation LMS existe déjà pour cette KLASSCI evaluation.
        $klassciEvaluationId = $data['klassci_evaluation_id'] ?? null;
        if ($klassciEvaluationId) {
            $existing = Evaluation::where('klassci_evaluation_id', $klassciEvaluationId)->first();
            if ($existing) {
                return $this->conflict($existing);
            }
        }

        try {
            $this->db->beginTransaction();

            [$matiereNom, $classeNom] = $this->resolveKlassciLabels(
                $data['klassci_matiere_id'] ?? null,
                $data['klassci_classe_id'] ?? null,
            );

            $evaluation = Evaluation::create(array_merge(
                array_intersect_key($data, array_flip(self::FILLABLE_FROM_REQUEST)),
                [
                    'matiere_nom'           => $matiereNom,
                    'classe_nom'            => $classeNom,
                    'klassci_enseignant_id' => $teacher->klassci_enseignant_id,
                    // Scope tenant explicite (fix E2E #211 flow 2).
                    'institution_id'        => $teacher->institution_id,
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
            $this->logger->error('Erreur création évaluation', ['error' => $e->getMessage()]);

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
     * Résout les libellés matière/classe via KLASSCI (fail-soft : warn-log
     * + valeurs nulles si l'API échoue).
     *
     * @return array{0: ?string, 1: ?string}  [matiereNom, classeNom]
     */
    private function resolveKlassciLabels(?int $matiereId, ?int $classeId): array
    {
        $matiereNom = null;
        $classeNom = null;

        try {
            if ($matiereId) {
                $matieres = $this->klassciService->getMatieres();
                if (isset($matieres['data']) && is_array($matieres['data'])) {
                    foreach ($matieres['data'] as $matiere) {
                        if ((int) ($matiere['id'] ?? 0) === $matiereId) {
                            $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;
                            break;
                        }
                    }
                }
            }

            if ($classeId) {
                $classes = $this->klassciService->getClasses();
                if (isset($classes['data']) && is_array($classes['data'])) {
                    foreach ($classes['data'] as $classe) {
                        if ((int) ($classe['id'] ?? 0) === $classeId) {
                            $classeNom = $classe['libelle'] ?? $classe['nom'] ?? null;
                            if (! $classeNom && isset($classe['niveau']['code'])) {
                                $classeNom = $classe['niveau']['code'];
                            } elseif (! $classeNom && isset($classe['niveau']['libelle'])) {
                                $classeNom = $classe['niveau']['libelle'];
                            }
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Impossible de récupérer les noms depuis KLASSCI', [
                'error' => $e->getMessage(),
            ]);
        }

        return [$matiereNom, $classeNom];
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
