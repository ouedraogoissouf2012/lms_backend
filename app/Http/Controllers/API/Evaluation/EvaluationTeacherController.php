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
 * EvaluationTeacherController — extrait verbatim de EvaluationController
 * dans le cadre du split god-controller (1676 lignes -> 5 fichiers SRP).
 *
 * Aucun changement comportemental : les méthodes sont déplacées telles
 * quelles, avec injection DI cohérente. Phase 2 du refactor identifié
 * dans le bilan d'audit post-PR #149.
 */
class EvaluationTeacherController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private EvaluationEnrichmentService $enrichmentService,
    ) {}

    public function getResultsByClass(int $id): JsonResponse
    {
        try {
            // Récupérer l'évaluation avec ses questions et soumissions
            $evaluation = Evaluation::with(['questions', 'submissions'])->find($id);

            if (!$evaluation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Évaluation non trouvée'
                ], 404);
            }

            \Log::info('📊 Récupération résultats évaluation', [
                'evaluation_id' => $id,
                'classe_id' => $evaluation->klassci_classe_id
            ]);

            // Récupérer la liste COMPLÈTE des étudiants de la classe depuis KLASSCI
            $classeEtudiants = $this->klassciService->getClasseEtudiants($evaluation->klassci_classe_id);
            $etudiants = $classeEtudiants['data'] ?? [];

            \Log::info('👥 Étudiants de la classe', [
                'total_etudiants' => count($etudiants)
            ]);

            // Enrichir avec les données KLASSCI (classe, matière)
            $evaluationEnrichie = $this->enrichmentService->enrich(collect([$evaluation]))[0];

            // Créer un tableau de résultats pour TOUS les étudiants
            $resultats = [];
            foreach ($etudiants as $etudiant) {
                // Trouver le user local correspondant par email
                $userLocal = \App\Models\User::where('email', $etudiant['email'] ?? '')->first();

                // Récupérer la dernière soumission de l'étudiant pour cette évaluation
                // Essayer d'abord avec le KlassCI ID de l'user local, sinon avec l'ID KlassCI direct
                $submission = null;
                if ($userLocal && $userLocal->klassci_id) {
                    $submission = $evaluation->submissions()
                        ->where('klassci_etudiant_id', $userLocal->klassci_id)
                        ->where(function($q) {
                            $q->whereNull('feedback')
                              ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
                        })
                        ->latest()
                        ->first();
                }

                // Si pas trouvé, essayer avec l'ID KlassCI direct
                if (!$submission) {
                    $submission = $evaluation->submissions()
                        ->where('klassci_etudiant_id', $etudiant['id'])
                        ->where(function($q) {
                            $q->whereNull('feedback')
                              ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
                        })
                        ->latest()
                        ->first();
                }

                $resultats[] = [
                    'etudiant_id' => $etudiant['id'],
                    'etudiant_nom' => $etudiant['nom'] ?? '',
                    'etudiant_prenom' => $etudiant['prenom'] ?? '',
                    'etudiant_nom_complet' => trim(($etudiant['nom'] ?? '') . ' ' . ($etudiant['prenom'] ?? '')),
                    'note' => $submission?->note_sur_20,
                    'score' => $submission?->score,
                    'status' => $submission?->status ?? 'non_passee',
                    'submitted_at' => $submission?->submitted_at,
                    'attempt' => $submission?->attempt,
                    'feedback' => $submission?->feedback,
                ];
            }

            // Trier les résultats par nom
            usort($resultats, function($a, $b) {
                return strcmp($a['etudiant_nom_complet'], $b['etudiant_nom_complet']);
            });

            // Calculer les statistiques (inclure 'soumis' ET 'corrige')
            $soumissions = collect($resultats)->whereIn('status', ['soumis', 'corrige']);
            $notes = $soumissions->pluck('note')->filter();

            $statistiques = [
                'total_etudiants' => count($etudiants),
                'etudiants_soumis' => $soumissions->count(),
                'etudiants_en_cours' => collect($resultats)->where('status', 'en_cours')->count(),
                'etudiants_non_passes' => collect($resultats)->where('status', 'non_passee')->count(),
                'taux_participation' => count($etudiants) > 0
                    ? round(($soumissions->count() / count($etudiants)) * 100, 2)
                    : 0,
                'moyenne_classe' => $notes->count() > 0 ? round($notes->avg(), 2) : null,
                'note_max' => $notes->count() > 0 ? round($notes->max(), 2) : null,
                'note_min' => $notes->count() > 0 ? round($notes->min(), 2) : null,
            ];

            \Log::info('✅ Résultats calculés', [
                'total_etudiants' => $statistiques['total_etudiants'],
                'soumis' => $statistiques['etudiants_soumis'],
                'moyenne' => $statistiques['moyenne_classe']
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'evaluation' => $evaluationEnrichie,
                    'resultats' => $resultats,
                    'statistiques' => $statistiques
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('❌ Erreur récupération résultats évaluation', [
                'evaluation_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des résultats',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    public function getSubmissions(int $id): JsonResponse
    {
        try {
            $evaluation = Evaluation::findOrFail($id);

            // Récupérer toutes les soumissions avec informations étudiants
            $submissions = EvaluationSubmission::where('evaluation_id', $id)
                ->orderBy('submitted_at', 'desc')
                ->get();

            // Récupérer les noms des étudiants depuis la table User locale
            $klassciIds = $submissions->pluck('klassci_etudiant_id')->unique();
            $studentsMap = User::whereIn('klassci_id', $klassciIds)
                ->get()
                ->pluck('name', 'klassci_id')
                ->toArray();

            // Enrichir avec noms des étudiants
            $enrichedSubmissions = $submissions->map(function ($submission) use ($studentsMap) {
                return [
                    'id' => $submission->id,
                    'evaluation_id' => $submission->evaluation_id,
                    'klassci_etudiant_id' => $submission->klassci_etudiant_id,
                    'student_name' => $studentsMap[$submission->klassci_etudiant_id] ?? 'Étudiant #' . $submission->klassci_etudiant_id,
                    'attempt' => $submission->attempt,
                    'status' => $submission->status,
                    'started_at' => $submission->started_at,
                    'submitted_at' => $submission->submitted_at,
                    'score' => $submission->score,
                    'note_sur_20' => $submission->note_sur_20,
                    'synced_to_klassci' => $submission->synced_to_klassci,
                    'synced_at' => $submission->synced_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $enrichedSubmissions
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur récupération soumissions', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des soumissions',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    public function preview(int $id): JsonResponse
    {
        try {
            $evaluation = Evaluation::with('questions')->findOrFail($id);

            // Restriction coordinateur: ne peut prévisualiser que les évaluations terminées
            $user = auth()->user();
            if ($user?->isCoordinator()) {
                // Utilise la méthode centralisée du modèle (logique réutilisable)
                if (!$evaluation->isTerminee()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès refusé. Les coordinateurs ne peuvent prévisualiser que les évaluations terminées.'
                    ], 403);
                }
            }

            // Formater les questions pour la prévisualisation (sans correct_answers pour sécurité)
            $questionsForPreview = $evaluation->questions->map(function ($question) {
                return [
                    'id' => $question->id,
                    'question' => $question->question,
                    'type' => $question->type,
                    'points' => $question->points,
                    'ordre' => $question->ordre,
                    'options' => $question->options,
                    // Ne pas inclure correct_answers pour éviter de les exposer
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'description' => $evaluation->description,
                    'type' => $evaluation->type,
                    'duree_minutes' => $evaluation->duree_minutes,
                    'coefficient' => $evaluation->coefficient,
                    'bareme' => $evaluation->bareme,
                    'date_evaluation' => $evaluation->date_evaluation,
                    'questions' => $questionsForPreview,
                    'questions_count' => $questionsForPreview->count(),
                    'total_points' => $evaluation->questions->sum('points'),
                    'is_preview' => true
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur prévisualisation évaluation', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la prévisualisation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
