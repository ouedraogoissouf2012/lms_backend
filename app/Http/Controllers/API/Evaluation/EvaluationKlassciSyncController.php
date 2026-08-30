<?php

namespace App\Http\Controllers\API\Evaluation;

use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteEvaluationRequest;
use App\Http\Requests\PublishEvaluationRequest;
use App\Http\Requests\StartEvaluationRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\SubmitEvaluationRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EvaluationKlassciSyncController — extrait verbatim de EvaluationController
 * dans le cadre du split god-controller (1676 lignes -> 5 fichiers SRP).
 *
 * Aucun changement comportemental : les méthodes sont déplacées telles
 * quelles, avec injection DI cohérente. Phase 2 du refactor identifié
 * dans le bilan d'audit post-PR #149.
 */
class EvaluationKlassciSyncController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private EvaluationEnrichmentService $enrichmentService,
        private EvaluationGradingService $gradingService,
    ) {}

    public function syncToKlassci(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::with(['submissions', 'questions'])->find($id);

        if (!$evaluation) {
            return $this->errorResponse('Évaluation non trouvée', 404);
        }

        // #588 : une dissertation n'est finale qu'en statut `corrige`.
        if ($this->hasUngradedManualSubmission($evaluation)) {
            return $this->errorResponse(
                'Synchronisation bloquée : cette évaluation contient des questions à correction manuelle (dissertation) non encore notées.',
                409
            );
        }

        try {
            // Préparer les notes pour KLASSCI (exclure les entraînements)
            $notes = [];
            foreach ($evaluation->submissions as $submission) {
                if (($submission->status === 'soumis' || $submission->status === 'corrige')
                    && !str_starts_with($submission->feedback ?? '', '[PRACTICE]')) {
                    $notes[] = [
                        'etudiant_id' => $submission->klassci_etudiant_id,
                        'note' => $submission->note_sur_20,
                        'commentaire' => $submission->feedback,
                        'is_absent' => false,
                    ];
                }
            }

            // Envoyer vers KLASSCI si une évaluation KLASSCI existe
            if ($evaluation->klassci_evaluation_id) {
                $teacherToken = $this->authenticatedUser($request)->klassci_token;
                if (! is_string($teacherToken) || $teacherToken === '') {
                    return $this->errorResponse(MissingKlassciTokenException::CLIENT_MESSAGE, 401);
                }

                $result = $this->klassciService->saveNotes(
                    $teacherToken,
                    $evaluation->klassci_evaluation_id,
                    $notes
                );

                // Marquer comme synchronisé
                $evaluation->update(['notes_published' => true]);
                $evaluation->submissions()->update([
                    'synced_to_klassci' => true,
                    'synced_at' => now()
                ]);

                return $this->successResponse($result, 'Notes synchronisées vers KLASSCI');
            }

            return $this->errorResponse('Aucune évaluation KLASSCI liée', 400);

        } catch (\Exception $e) {
            \Log::error('Erreur synchronisation KLASSCI', ['error' => $e->getMessage()]);

            // Non migré vers errorResponse() : cette réponse porte une clé racine
            // `error` (singulier) hors contrat du trait ({success, message, errors?}).
            // La migrer supprimerait `error` → changerait le JSON client (interdit,
            // axe #1 « DRY-only »). Conservée inline.
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    public function syncNotesToKlassci(int $id): JsonResponse
    {
        try {
            $evaluation = Evaluation::with('questions')->findOrFail($id);

            if ($this->hasUngradedManualSubmission($evaluation)) {
                return $this->errorResponse(
                    'Synchronisation bloquée : cette évaluation contient des questions à correction manuelle (dissertation) non encore notées.',
                    409
                );
            }

            // Récupérer toutes les soumissions non synchronisées
            $submissions = EvaluationSubmission::where('evaluation_id', $id)
                ->where('status', 'soumis')
                ->where('synced_to_klassci', false)
                ->get();

            if ($submissions->isEmpty()) {
                // Non migré vers successResponse() : clé racine `synced_count` hors
                // contrat du trait ({success, message, data?, meta?}). La déplacer
                // (sous `data`/`meta`) changerait le JSON client. Conservée inline.
                return response()->json([
                    'success' => true,
                    'message' => 'Toutes les notes sont déjà synchronisées',
                    'synced_count' => 0
                ]);
            }

            $syncedCount = 0;
            $errors = [];

            // STUB DOCUMENTÉ — l'appel POST KLASSCI `/api/notes` n'est pas encore implémenté.
            // En attendant, on marque les soumissions comme synced_to_klassci pour ne pas les
            // re-tenter en boucle, mais on log un warning à chaque appel pour rendre la dette
            // visible dans les dashboards. À fixer dans une issue follow-up dédiée.
            \Log::warning('syncNotesToKlassci is stubbed — submissions marked synced without KLASSCI POST', [
                'evaluation_id'        => $id,
                'submissions_affected' => $submissions->count(),
            ]);

            foreach ($submissions as $submission) {
                try {
                    $submission->update([
                        'synced_to_klassci' => true,
                        'synced_at'         => now(),
                    ]);

                    $syncedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'submission_id' => $submission->id,
                        'student_id' => $submission->klassci_etudiant_id,
                        'error' => 'Une erreur est survenue.'
                    ];
                }
            }

            \Log::info('Synchronisation notes vers KLASSCI', [
                'evaluation_id' => $id,
                'evaluation_titre' => $evaluation->titre,
                'total_submissions' => $submissions->count(),
                'synced_count' => $syncedCount,
                'errors_count' => count($errors)
            ]);

            // Non migré vers successResponse() : clés racine `synced_count` et
            // `errors` (sur un succès) hors contrat du trait. Les déplacer
            // changerait le JSON client (axe #1 « DRY-only »). Conservée inline.
            return response()->json([
                'success' => true,
                'message' => "Synchronisation terminée : {$syncedCount} note(s) synchronisée(s)",
                'synced_count' => $syncedCount,
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur synchronisation notes', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            // Non migré : clé racine `error` (singulier) hors contrat du trait
            // (cf. syncToKlassci). Conservée inline pour préserver le JSON client.
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des notes',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Vrai si l'évaluation contient au moins une question à correction manuelle
     * (dissertation) — donc dont la note automatique n'est pas finale (#564).
     * La règle « quel type est manuel » reste centralisée dans le service de
     * correction (DRY), le contrôleur ne fait qu'orchestrer le fail-closed.
     */
    private function hasManualGradingQuestion(Evaluation $evaluation): bool
    {
        return $evaluation->questions->contains(
            fn (EvaluationQuestion $question): bool => $this->gradingService->requiresManualGrading($question)
        );
    }

    private function hasUngradedManualSubmission(Evaluation $evaluation): bool
    {
        if (! $this->hasManualGradingQuestion($evaluation)) {
            return false;
        }

        $evaluation->loadMissing('submissions');

        return $evaluation->submissions->contains(
            fn (EvaluationSubmission $submission): bool => $submission->status === 'soumis'
        );
    }
}
