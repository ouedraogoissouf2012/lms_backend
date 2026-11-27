<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\OuvertureDuCorrige;
use App\Services\Evaluation\Student\ResultatsVisibles;
use App\Support\Logging\CaughtExceptionLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Lecture de la dernière soumission étudiant pour une évaluation, avec son
 * corrigé quand la règle d'ouverture le permet ({@see OuvertureDuCorrige}, #1699).
 *
 * Extrait de `EvaluationStudentController::getMySubmission` (split §5).
 *
 * Endpoint : GET /api/evaluations/{id}/my-submission
 */
final class EvaluationStudentSubmissionController extends AuthenticatedController
{
    public function __construct(
        private readonly CaughtExceptionLog $journal,
        private readonly OuvertureDuCorrige $corrige,
    ) {}

    public function getMySubmission(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            // D72, E2a : la copie appartient à son auteur par `student_id`
            // (EvaluationSubmission::ownedBy) ; l'identité KLASSCI n'est plus
            // exigée. Sans copie à soi : 404.

            $submission = $this->ownLatestCopy($id, $user);
            $evaluation = $submission?->evaluation;
            if (! $submission instanceof EvaluationSubmission || ! $evaluation instanceof Evaluation) {
                return $this->errorResponse('Aucune soumission trouvée pour cette évaluation', 404);
            }

            $corrige = $this->corrige->pour($user, $evaluation, Carbon::now());
            // La règle « résultats visibles » (C25), partagée avec la liste et la remise. Le
            // commentaire de l'enseignant suit la note (#1699) : il la dévoilerait.
            $visibles = ResultatsVisibles::pour($evaluation);

            return $this->successResponse([
                'id' => $submission->id,
                'evaluation_id' => $submission->evaluation_id,
                'attempt' => $submission->attempt,
                'status' => $submission->status,
                'started_at' => $submission->started_at,
                'ends_at' => $submission->ends_at,
                'submitted_at' => $submission->submitted_at,
                'score' => $visibles ? $submission->score : null,
                'note_sur_20' => $visibles ? $submission->note_sur_20 : null,
                'feedback' => $visibles ? $submission->feedback : null,
                'questions' => $this->questionsDe($evaluation, $corrige['ouvert']),
                // Toujours un objet JSON, `{}` quand la copie est vide : un tableau vide
                // s'encoderait `[]`, contre la spec et la prop `Object` du front.
                'answers' => (object) ($submission->answers ?? []),
                'correction_available' => $corrige['ouvert'],
                'correction_available_at' => $corrige['ouvre_le']?->toIso8601String(),
                // Déprécié (#1699) : plus de délai fixe. Retiré quand le front ne le lit plus.
                'correction_delay_days' => null,
                'evaluation' => [
                    'id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'bareme' => $evaluation->bareme,
                    'coefficient' => $evaluation->coefficient,
                ],
            ]);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération soumission étudiant', $e, [
                'evaluation_id' => $id,
            ]);

            return $this->errorResponse('Erreur lors de la récupération de la soumission', 500);
        }
    }

    /**
     * Les questions de la copie, dans l'ordre de l'évaluation (mélangées si elle le
     * demande).
     *
     * Le corrigé est masqué par défaut sur le modèle ({@see
     * \App\Models\EvaluationQuestion}). Ce chemin — la copie de l'élève lui-même,
     * une fois le corrigé ouvert — est le seul à le révéler, et il le fait explicitement.
     * L'ancien `unset` faisait l'inverse : il retirait ce que tous les autres
     * chemins laissaient passer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function questionsDe(Evaluation $evaluation, bool $correctionAvailable): Collection
    {
        $questions = $evaluation->questions()->get();
        if ($evaluation->shuffle_questions) {
            $questions = $questions->shuffle()->values();
        }

        return $questions->map(static function ($question) use ($correctionAvailable) {
            if ($correctionAvailable) {
                $question = (clone $question)->makeVisible(['correct_answers', 'explanation']);
            }

            return $question->toArray();
        });
    }

    /**
     * La dernière copie de CET élève sur cette évaluation, dans SON établissement.
     *
     * Le droit de lire sa copie tient à la PROPRIÉTÉ de la copie, pas à sa
     * classe actuelle : l'écran des notes (my-grades) propose aussi les copies
     * des classes passées. Le démarrage d'une copie passe désormais par la
     * portée de l'élève (LearnerEvaluationScope) ; les copies plus anciennes
     * restent lisibles par leur auteur.
     *
     * La copie est celle de l'élève DANS SON établissement (`ownedBy` : sans
     * établissement, rien). L'évaluation est cherchée dans ce même
     * établissement, explicitement, sans s'en remettre au scope global, ouvert
     * quand aucun tenant n'est posé. Une évaluation absente, d'une autre école
     * ou sans copie de l'élève rendent le même 404.
     */
    private function ownLatestCopy(int $evaluationId, User $user): ?EvaluationSubmission
    {
        return EvaluationSubmission::query()
            ->where('evaluation_id', $evaluationId)
            ->ownedBy($user)
            ->whereIn('evaluation_id', Evaluation::query()->select('id')->where('institution_id', $user->institution_id))
            ->with('evaluation')
            ->orderBy('attempt', 'desc')
            ->first();
    }
}
