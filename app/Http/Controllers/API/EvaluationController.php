<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour gérer les évaluations en ligne
 */
class EvaluationController extends Controller
{
    public function __construct(
        private KlassciProxyService $klassciService
    ) {}

    /**
     * GET /api/evaluations
     * Liste toutes les évaluations (avec filtres)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Evaluation::with(['questions', 'submissions']);

        // Filtres
        if ($request->has('classe_id')) {
            $query->where('klassci_classe_id', $request->classe_id);
        }

        if ($request->has('matiere_id')) {
            $query->where('klassci_matiere_id', $request->matiere_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $evaluations = $query->orderBy('date_evaluation', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $evaluations
        ]);
    }

    /**
     * GET /api/evaluations/{id}
     * Récupère une évaluation spécifique avec ses questions
     */
    public function show(int $id): JsonResponse
    {
        $evaluation = Evaluation::with('questions')->find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $evaluation
        ]);
    }

    /**
     * POST /api/evaluations
     * Crée une nouvelle évaluation
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'klassci_matiere_id' => 'required|integer',
            'klassci_classe_id' => 'required|integer',
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:qcm,reponse_courte,dissertation,mixte',
            'date_evaluation' => 'nullable|date',
            'duree_minutes' => 'required|integer|min:1',
            'coefficient' => 'nullable|numeric|min:0',
            'bareme' => 'nullable|numeric|min:0',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required|string',
            'questions.*.type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
            'questions.*.points' => 'nullable|numeric|min:0',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Créer l'évaluation
            $evaluation = Evaluation::create($request->only([
                'klassci_matiere_id',
                'klassci_classe_id',
                'klassci_enseignant_id',
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
            ]));

            // Créer les questions si fournies
            if ($request->has('questions')) {
                foreach ($request->questions as $index => $questionData) {
                    EvaluationQuestion::create([
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question'],
                        'type' => $questionData['type'],
                        'ordre' => $index + 1,
                        'points' => $questionData['points'] ?? 1,
                        'options' => $questionData['options'] ?? null,
                        'correct_answers' => $questionData['correct_answers'] ?? null,
                        'explanation' => $questionData['explanation'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $evaluation->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Évaluation créée avec succès',
                'data' => $evaluation
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création évaluation', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'évaluation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/evaluations/{id}
     * Met à jour une évaluation
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'titre' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:brouillon,planifiee,en_cours,terminee,annulee',
            'date_evaluation' => 'nullable|date',
            'duree_minutes' => 'sometimes|integer|min:1',
            'is_published' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $evaluation->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Évaluation mise à jour',
                'data' => $evaluation
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/evaluations/{id}
     * Supprime une évaluation
     */
    public function destroy(int $id): JsonResponse
    {
        $evaluation = Evaluation::find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        $evaluation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Évaluation supprimée'
        ]);
    }

    /**
     * POST /api/evaluations/{id}/publish
     * Publie une évaluation (la rend visible aux étudiants)
     */
    public function publish(int $id): JsonResponse
    {
        $evaluation = Evaluation::find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        $evaluation->update([
            'is_published' => true,
            'status' => 'planifiee'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Évaluation publiée',
            'data' => $evaluation
        ]);
    }

    /**
     * GET /api/evaluations/student/{klassciEtudiantId}
     * Récupère les évaluations disponibles pour un étudiant
     */
    public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
    {
        // Récupérer la classe de l'étudiant depuis le dashboard
        try {
            $authHeader = $request->header('Authorization');
            $userToken = substr($authHeader, 7);

            $dashboard = $this->klassciService->requestWithUserToken(
                $userToken,
                'me/dashboard',
                'GET'
            );

            $classeId = $dashboard['data']['classe']['id'] ?? null;

            if (!$classeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Classe non trouvée'
                ], 404);
            }

            // Récupérer les évaluations publiées pour cette classe
            $evaluations = Evaluation::with('questions', 'submissions')
                ->where('klassci_classe_id', $classeId)
                ->where('is_published', true)
                ->whereIn('status', ['planifiee', 'en_cours'])
                ->orderBy('date_evaluation', 'desc')
                ->get();

            // Ajouter les informations de soumission pour cet étudiant
            $evaluations->each(function ($evaluation) use ($klassciEtudiantId) {
                $submission = $evaluation->submissions()
                    ->where('klassci_etudiant_id', $klassciEtudiantId)
                    ->latest()
                    ->first();

                $evaluation->student_submission = $submission;
            });

            return response()->json([
                'success' => true,
                'data' => $evaluations
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération évaluations étudiant', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des évaluations'
            ], 500);
        }
    }

    /**
     * POST /api/evaluations/{id}/start
     * Démarre une évaluation pour un étudiant
     */
    public function startEvaluation(int $id, Request $request): JsonResponse
    {
        $evaluation = Evaluation::find($id);

        if (!$evaluation || !$evaluation->isAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non disponible'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'klassci_etudiant_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $klassciEtudiantId = $request->klassci_etudiant_id;

        // Vérifier le nombre de tentatives
        $attemptsCount = EvaluationSubmission::where('evaluation_id', $id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->count();

        if ($attemptsCount >= $evaluation->max_attempts && !$evaluation->allow_retake) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint'
            ], 403);
        }

        // Créer une nouvelle soumission
        $submission = EvaluationSubmission::create([
            'evaluation_id' => $id,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'attempt' => $attemptsCount + 1,
            'status' => 'en_cours',
            'started_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Évaluation démarrée',
            'data' => $submission
        ]);
    }

    /**
     * POST /api/evaluations/{id}/submit
     * Soumet les réponses d'une évaluation
     */
    public function submitEvaluation(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'submission_id' => 'required|integer',
            'answers' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $submission = EvaluationSubmission::find($request->submission_id);

        if (!$submission || $submission->evaluation_id != $id) {
            return response()->json([
                'success' => false,
                'message' => 'Soumission non trouvée'
            ], 404);
        }

        if ($submission->status !== 'en_cours') {
            return response()->json([
                'success' => false,
                'message' => 'Cette évaluation a déjà été soumise'
            ], 403);
        }

        try {
            $submission->answers = $request->answers;
            $submission->submit(); // Calcule automatiquement le score

            return response()->json([
                'success' => true,
                'message' => 'Évaluation soumise avec succès',
                'data' => [
                    'submission' => $submission,
                    'score' => $submission->score,
                    'note_sur_20' => $submission->note_sur_20,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur soumission évaluation', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission'
            ], 500);
        }
    }

    /**
     * POST /api/evaluations/{id}/sync-to-klassci
     * Synchronise les notes vers KLASSCI
     */
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
            // Préparer les notes pour KLASSCI
            $notes = [];
            foreach ($evaluation->submissions as $submission) {
                if ($submission->status === 'soumis' || $submission->status === 'corrige') {
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
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
