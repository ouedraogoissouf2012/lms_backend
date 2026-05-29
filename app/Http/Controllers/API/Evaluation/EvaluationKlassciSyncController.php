<?php

namespace App\Http\Controllers\API\Evaluation;

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
    ) {}

    public function syncToKlassci(int $id): JsonResponse
    {
        $evaluation = Evaluation::with('submissions')->find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
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
                $result = $this->klassciService->saveNotes(
                    $evaluation->klassci_evaluation_id,
                    $notes
                );

                // Marquer comme synchronisé
                $evaluation->update(['notes_published' => true]);
                $evaluation->submissions()->update([
                    'synced_to_klassci' => true,
                    'synced_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Notes synchronisées vers KLASSCI',
                    'data' => $result
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Aucune évaluation KLASSCI liée'
            ], 400);

        } catch (\Exception $e) {
            \Log::error('Erreur synchronisation KLASSCI', ['error' => $e->getMessage()]);

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
            $evaluation = Evaluation::findOrFail($id);

            // Récupérer toutes les soumissions non synchronisées
            $submissions = EvaluationSubmission::where('evaluation_id', $id)
                ->where('status', 'soumis')
                ->where('synced_to_klassci', false)
                ->get();

            if ($submissions->isEmpty()) {
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

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des notes',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
