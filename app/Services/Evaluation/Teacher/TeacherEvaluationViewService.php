<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vues enseignant — liste des soumissions et prévisualisation d'évaluation.
 *
 * Split §22 du god-controller : extrait `EvaluationTeacherController::
 * getSubmissions` (~52 l) et `::preview` (~60 l).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte uniquement PSR-3 `LoggerInterface`. Aucun `app()`,
 * aucune Facade `Log::` ni `auth()`. L'identité du caller est passée en
 * paramètre (`User $teacher`), jamais résolue depuis le container.
 *
 * ## Comportement préservé
 *
 * - `getSubmissions` : tri par `submitted_at desc`, enrichissement avec
 *   le nom du user local (lookup batched par `klassci_id`), fallback
 *   `'Étudiant #{id}'` si pas mappé.
 * - `preview` : restriction coordinateur (uniquement évaluations terminées),
 *   formatage des questions SANS `correct_answers` (sécurité), métadonnées
 *   complètes (questions_count, total_points, is_preview=true).
 *
 * Le caller (`EvaluationTeacherController`) se contente de `response()
 * ->json($result['payload'], $result['status'])`.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationTeacherController.php
 */
final class TeacherEvaluationViewService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste les soumissions d'une évaluation, enrichies du nom étudiant.
     *
     * Le `User $teacher` est conservé pour audit log + future autorisation
     * fine (cf. plan multi-tenant post-PR #173).
     *
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function getSubmissions(int $evaluationId, User $teacher): array
    {
        try {
            // `findOrFail` ici uniquement pour préserver le 404 historique —
            // l'instance n'est pas réutilisée (les submissions sont récupérées
            // par evaluation_id).
            Evaluation::findOrFail($evaluationId);

            $submissions = EvaluationSubmission::where('evaluation_id', $evaluationId)
                ->orderBy('submitted_at', 'desc')
                ->get();

            // Lookup batched des noms étudiants — évite N+1 sur la map.
            $klassciIds   = $submissions->pluck('klassci_etudiant_id')->unique();
            $studentsMap  = User::whereIn('klassci_id', $klassciIds)
                ->get()
                ->pluck('name', 'klassci_id')
                ->toArray();

            $enrichedSubmissions = $submissions->map(static function (EvaluationSubmission $submission) use ($studentsMap): array {
                return [
                    'id'                    => $submission->id,
                    'evaluation_id'         => $submission->evaluation_id,
                    'klassci_etudiant_id'   => $submission->klassci_etudiant_id,
                    'student_name'          => $studentsMap[$submission->klassci_etudiant_id]
                        ?? 'Étudiant #' . $submission->klassci_etudiant_id,
                    'attempt'               => $submission->attempt,
                    'status'                => $submission->status,
                    'started_at'            => $submission->started_at,
                    'submitted_at'          => $submission->submitted_at,
                    'score'                 => $submission->score,
                    'note_sur_20'           => $submission->note_sur_20,
                    'synced_to_klassci'     => $submission->synced_to_klassci,
                    'synced_at'             => $submission->synced_at,
                ];
            });

            return [
                'status'  => 200,
                'payload' => [
                    'success' => true,
                    'data'    => $enrichedSubmissions,
                ],
            ];
        } catch (ModelNotFoundException) {
            return [
                'status'  => 404,
                'payload' => [
                    'success' => false,
                    'message' => 'Évaluation non trouvée',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération soumissions', [
                'evaluation_id' => $evaluationId,
                'teacher_id'    => $teacher->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'status'  => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération des soumissions',
                    'error'   => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Prévisualisation enseignant d'une évaluation (avant publication).
     *
     * Sécurité :
     * - Coordinateur restreint aux évaluations `terminee` (cf. modèle :
     *   `Evaluation::isTerminee()`).
     * - Questions exposées SANS `correct_answers` (ne JAMAIS exposer
     *   les bonnes réponses dans un endpoint de preview).
     *
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function preview(int $evaluationId, User $teacher): array
    {
        try {
            $evaluation = Evaluation::with('questions')->findOrFail($evaluationId);

            // Restriction coordinateur : preview limitée aux évaluations
            // terminées (politique métier — cf. modèle `isTerminee()`).
            if ($teacher->isCoordinator() && ! $evaluation->isTerminee()) {
                return [
                    'status'  => 403,
                    'payload' => [
                        'success' => false,
                        'message' => 'Accès refusé. Les coordinateurs ne peuvent prévisualiser que les évaluations terminées.',
                    ],
                ];
            }

            $questionsForPreview = $evaluation->questions->map(static function ($question): array {
                return [
                    'id'      => $question->id,
                    'question' => $question->question,
                    'type'    => $question->type,
                    'points'  => $question->points,
                    'ordre'   => $question->ordre,
                    'options' => $question->options,
                    // ⚠️ ne PAS exposer `correct_answers` — sécurité.
                ];
            });

            return [
                'status'  => 200,
                'payload' => [
                    'success' => true,
                    'data'    => [
                        'id'              => $evaluation->id,
                        'titre'           => $evaluation->titre,
                        'description'     => $evaluation->description,
                        'type'            => $evaluation->type,
                        'duree_minutes'   => $evaluation->duree_minutes,
                        'coefficient'     => $evaluation->coefficient,
                        'bareme'          => $evaluation->bareme,
                        'date_evaluation' => $evaluation->date_evaluation,
                        'questions'       => $questionsForPreview,
                        'questions_count' => $questionsForPreview->count(),
                        'total_points'    => $evaluation->questions->sum('points'),
                        'is_preview'      => true,
                    ],
                ],
            ];
        } catch (ModelNotFoundException) {
            return [
                'status'  => 404,
                'payload' => [
                    'success' => false,
                    'message' => 'Évaluation non trouvée',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur prévisualisation évaluation', [
                'evaluation_id' => $evaluationId,
                'teacher_id'    => $teacher->id,
                'error'         => $e->getMessage(),
            ]);

            return [
                'status'  => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la prévisualisation',
                    'error'   => 'Une erreur est survenue.',
                ],
            ];
        }
    }
}
