<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Vues enseignant — liste des soumissions et prévisualisation d'évaluation.
 *
 * Split §22 du god-controller : extrait `EvaluationTeacherController::
 * getSubmissions` (~52 l) et `::preview` (~60 l).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte uniquement `CaughtExceptionLog` (journal des exceptions
 * attrapées). Aucun `app()`, aucune Facade `Log::` ni `auth()`. L'identité du
 * caller est passée en
 * paramètre (`User $teacher`), jamais résolue depuis le container.
 *
 * ## Comportement préservé
 *
 * - `getSubmissions` : tri par `submitted_at desc`, enrichissement avec
 *   le nom du user local (par l'auteur `student_id`, puis par `klassci_id`
 *   pour une ligne d'avant D72, dans l'établissement de l'évaluation : D72 E3),
 *   fallback `'Étudiant #{id}'` si pas mappé.
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
        private readonly CaughtExceptionLog $journal,
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
            $evaluation = Evaluation::findOrFail($evaluationId);

            $submissions = EvaluationSubmission::where('evaluation_id', $evaluationId)
                ->orderBy('submitted_at', 'desc')
                ->get();

            $noms = $this->nomsDesAuteurs($submissions, $evaluation->institution_id);

            $enrichedSubmissions = $submissions->map(static function (EvaluationSubmission $submission) use ($noms): array {
                return [
                    'id'                    => $submission->id,
                    'evaluation_id'         => $submission->evaluation_id,
                    'student_id'            => $submission->student_id,
                    'klassci_etudiant_id'   => $submission->klassci_etudiant_id,
                    'student_name'          => $noms($submission),
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
            $this->journal->error('Erreur récupération soumissions', $e, [
                'evaluation_id' => $evaluationId,
                'teacher_id' => $teacher->id,
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
     * Le nom de l'élève de chaque copie, en deux requêtes pour toute la liste
     * (et non une par copie) : par son auteur (`student_id`, seul présent dans
     * une école créée depuis le LMS), puis, pour une ligne d'avant D72, par son
     * identité KLASSCI. Même ordre que `EvaluationSubmission::scopeOwnedBy()`.
     *
     * Les deux recherches sont bornées à l'établissement de l'évaluation : une
     * identité KLASSCI n'est unique qu'au sein d'une école, et le nom d'un
     * compte d'une autre école serait celui d'un tiers. Sans nom, « Étudiant #id ».
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission>  $copies
     * @return \Closure(EvaluationSubmission): string
     */
    private function nomsDesAuteurs(\Illuminate\Database\Eloquent\Collection $copies, ?int $etablissement): \Closure
    {
        [$parAuteur, $parKlassci] = [[], []];

        // `where(col, null)` deviendrait `IS NULL` : un établissement inconnu
        // ne nomme personne.
        if ($etablissement !== null) {
            $parAuteur = User::query()
                ->where('institution_id', $etablissement)
                ->whereIn('id', $copies->pluck('student_id')->filter()->unique()->values())
                ->pluck('name', 'id')
                ->all();
            $parKlassci = User::query()
                ->where('institution_id', $etablissement)
                ->whereIn('klassci_id', $copies->pluck('klassci_etudiant_id')->filter()->unique()->values())
                ->pluck('name', 'klassci_id')
                ->all();
        }

        return static function (EvaluationSubmission $copie) use ($parAuteur, $parKlassci): string {
            $nom = $parAuteur[$copie->student_id ?? ''] ?? $parKlassci[$copie->klassci_etudiant_id ?? ''] ?? null;

            return is_string($nom) ? $nom : 'Étudiant #' . ($copie->klassci_etudiant_id ?? $copie->student_id);
        };
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
            $this->journal->error('Erreur prévisualisation évaluation', $e, [
                'evaluation_id' => $evaluationId,
                'teacher_id' => $teacher->id,
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
