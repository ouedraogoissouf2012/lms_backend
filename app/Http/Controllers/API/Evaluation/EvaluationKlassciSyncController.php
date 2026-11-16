<?php

namespace App\Http\Controllers\API\Evaluation;

use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteEvaluationRequest;
use App\Http\Requests\PublishEvaluationRequest;
use App\Http\Requests\StartEvaluationRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\SubmitEvaluationRequest;
use App\Http\Requests\SyncEvaluationNotesRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\KlassciProxyService;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
    use RendersKlassciBackedErrors;

    public function __construct(
        private KlassciProxyService $klassciService,
        private EvaluationEnrichmentService $enrichmentService,
        private EvaluationGradingService $gradingService,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function syncToKlassci(SyncEvaluationNotesRequest $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::with(['submissions', 'questions'])->find($id);

        if (!$evaluation) {
            return $this->errorResponse('Évaluation non trouvée', 404);
        }

        if (($refus = $this->refusAvantSynchronisation($evaluation)) !== null) {
            return $refus;
        }

        try {
            // Les copies à synchroniser : une seule règle, celle que compte la liste (D540).
            // Choisies UNE fois : l'envoi et le marquage lisent les mêmes (#1097).
            $copies = $evaluation->submissions()->aSynchroniser()->get();
            $notes = $copies
                ->map(static fn (EvaluationSubmission $submission): array => [
                    'etudiant_id' => $submission->klassci_etudiant_id,
                    'note' => $submission->note_sur_20,
                    'commentaire' => $submission->feedback,
                    'is_absent' => false,
                ])->all();

            // Le lien KLASSCI est acquis : refusé plus haut sinon.
            $teacherToken = $this->authenticatedUser($request)->klassci_token;
            if (! is_string($teacherToken) || $teacherToken === '') {
                return MissingKlassciTokenException::jsonResponse();
            }

            // D539 : rien à envoyer. Refus motivé AVANT tout appel à KLASSCI, sans
            // marquer l'évaluation publiée ni ses copies synchronisées.
            if ($notes === []) {
                return $this->errorResponse(
                    'Aucune copie remise à synchroniser : rien n\'a été envoyé à KLASSCI.',
                    422,
                    reason: 'no_submission_to_sync',
                );
            }

            $result = $this->klassciService->saveNotes(
                $teacherToken,
                (int) $evaluation->klassci_evaluation_id,
                $notes
            );

            // Un échec que KLASSCI DÉCLARE en 2xx n'est pas un enregistrement : rien
            // n'est marqué. Seule `success === false` est lue : le corps réel de
            // cette route n'est pas capturé, aucune autre forme n'est interprétée.
            if (($result['success'] ?? null) === false) {
                throw KlassciUnavailableException::invalidPayload("evaluations/{$evaluation->klassci_evaluation_id}/notes");
            }

            // Marquer comme synchronisées les copies ENVOYÉES, et elles seules : une copie
            // en cours, verrouillée ou d'entraînement n'est pas partie (#1097).
            $evaluation->update(['notes_published' => true]);
            $evaluation->submissions()->whereKey($copies->modelKeys())->update([
                'synced_to_klassci' => true,
                'synced_at' => now()
            ]);

            return $this->successResponse($result, 'Notes synchronisées vers KLASSCI');
        } catch (RuntimeException $e) {
            // La règle du dépôt : 401 motivé, 4xx relayé, panne en 503 + Retry-After.
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            $this->journal->error('Erreur synchronisation KLASSCI', $e);

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

    /**
     * Les refus de la synchronisation, dans cet ordre.
     *
     * 1. Une évaluation sans lien KLASSCI (`klassci_evaluation_id` nul) n'a nulle
     *    part où envoyer ses notes : celle d'une école créée depuis le LMS, ou une
     *    ligne jamais liée. Refus motivé, en 422 (D72, E3). C'est un fait de la
     *    donnée, pas une comparaison de mode. Avant : `sync-klassci` répondait
     *    400 sans motif. (`sync-notes`, qui marquait les copies synchronisées
     *    sans rien envoyer, est retirée : D606.)
     * 2. #588 : une dissertation n'est finale qu'en statut `corrige` (409).
     */
    private function refusAvantSynchronisation(Evaluation $evaluation): ?JsonResponse
    {
        if ($evaluation->klassci_evaluation_id === null) {
            return $this->errorResponse(
                'Cette évaluation n\'est liée à aucune évaluation KLASSCI : ses notes restent dans le LMS.',
                422,
                reason: 'evaluation_not_linked_to_klassci',
            );
        }

        if ($this->hasUngradedManualSubmission($evaluation)) {
            return $this->errorResponse(
                'Synchronisation bloquée : cette évaluation contient des questions à correction manuelle (dissertation) non encore notées.',
                409
            );
        }

        return null;
    }

    /**
     * Vrai si l'évaluation contient au moins une question à correction manuelle
     * (dissertation) — donc dont la note automatique n'est pas finale (#564).
     * La règle « quel type est manuel » reste centralisée dans le service de
     * correction (DRY), le contrôleur ne fait qu'orchestrer le fail-closed.
     */
    private function hasManualGradingQuestion(Evaluation $evaluation): bool
    {
        return $this->gradingService->evaluationRequiresManualGrading($evaluation);
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
