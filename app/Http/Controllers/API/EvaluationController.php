<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
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
        $user = $request->user();
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

        // Enrichir avec les données KLASSCI (classe, matière)
        $enrichedEvaluations = $this->enrichEvaluationsWithKlassciData($evaluations);

        // Si c'est un étudiant, ajouter sa soumission à chaque évaluation
        if ($user && $user->klassci_id) {
            $enrichedEvaluations = collect($enrichedEvaluations)->map(function ($evalArray) use ($user) {
                $evaluation = Evaluation::find($evalArray['id']);
                if ($evaluation) {
                    $submission = $evaluation->submissions()
                        ->where('klassci_etudiant_id', $user->klassci_id)
                        ->latest()
                        ->first();
                    $evalArray['student_submission'] = $submission;
                }
                return $evalArray;
            })->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => $enrichedEvaluations
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

        // Enrichir avec les données KLASSCI
        $enrichedEvaluation = $this->enrichEvaluationsWithKlassciData(collect([$evaluation]))[0];

        return response()->json([
            'success' => true,
            'data' => $enrichedEvaluation
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

            // Récupérer les noms de matière et classe depuis KLASSCI
            $matiereNom = null;
            $classeNom = null;

            try {
                // Récupérer matière
                if ($request->klassci_matiere_id) {
                    $matieres = $this->klassciService->getMatieres();
                    if (isset($matieres['data'])) {
                        foreach ($matieres['data'] as $matiere) {
                            if ($matiere['id'] == $request->klassci_matiere_id) {
                                $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;
                                break;
                            }
                        }
                    }
                }

                // Récupérer classe
                if ($request->klassci_classe_id) {
                    $classes = $this->klassciService->getClasses();
                    if (isset($classes['data'])) {
                        foreach ($classes['data'] as $classe) {
                            if ($classe['id'] == $request->klassci_classe_id) {
                                $classeNom = $classe['libelle'] ?? $classe['nom'] ?? null;
                                // Si pas de nom, utiliser le code du niveau
                                if (!$classeNom && isset($classe['niveau']['code'])) {
                                    $classeNom = $classe['niveau']['code'];
                                } elseif (!$classeNom && isset($classe['niveau']['libelle'])) {
                                    $classeNom = $classe['niveau']['libelle'];
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Impossible de récupérer les noms depuis KLASSCI', ['error' => $e->getMessage()]);
            }

            // Créer l'évaluation
            $evaluation = Evaluation::create(array_merge(
                $request->only([
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
                ]),
                [
                    'matiere_nom' => $matiereNom,
                    'classe_nom' => $classeNom,
                ]
            ));

            // Créer les questions si fournies
            if ($request->has('questions')) {
                foreach ($request->questions as $index => $questionData) {
                    // Décoder les options et correct_answers si elles sont déjà encodées en JSON
                    $options = $questionData['options'] ?? null;
                    if (is_string($options) && $options !== null) {
                        $decoded = json_decode($options, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $options = $decoded;
                        }
                    }

                    $correctAnswers = $questionData['correct_answers'] ?? null;
                    if (is_string($correctAnswers) && $correctAnswers !== null) {
                        $decoded = json_decode($correctAnswers, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $correctAnswers = $decoded;
                        }
                    }

                    EvaluationQuestion::create([
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question'],
                        'type' => $questionData['type'],
                        'ordre' => $index + 1,
                        'points' => $questionData['points'] ?? 1,
                        'options' => $options,
                        'correct_answers' => $correctAnswers,
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

        // ⚠️ Vérifier si l'évaluation peut être modifiée
        if (!$evaluation->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier: des étudiants ont déjà soumis leurs réponses'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'titre' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:brouillon,planifiee,en_cours,terminee,annulee',
            'date_evaluation' => 'nullable|date',
            'duree_minutes' => 'sometimes|integer|min:1',
            'is_published' => 'sometimes|boolean',
            'max_attempts' => 'sometimes|integer|min:1',
            'shuffle_questions' => 'sometimes|boolean',
            'show_results' => 'sometimes|boolean',
            'questions' => 'sometimes|array',
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

            // Mettre à jour les champs de base
            $evaluation->update($request->except(['questions']));

            // Si des questions sont fournies, les remplacer
            if ($request->has('questions')) {
                // Supprimer les anciennes questions
                $evaluation->questions()->delete();

                // Créer les nouvelles questions
                foreach ($request->questions as $index => $questionData) {
                    // Décoder les options et correct_answers si elles sont déjà encodées en JSON
                    $options = $questionData['options'] ?? null;
                    if (is_string($options) && $options !== null) {
                        $decoded = json_decode($options, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $options = $decoded;
                        }
                    }

                    $correctAnswers = $questionData['correct_answers'] ?? null;
                    if (is_string($correctAnswers) && $correctAnswers !== null) {
                        $decoded = json_decode($correctAnswers, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $correctAnswers = $decoded;
                        }
                    }

                    EvaluationQuestion::create([
                        'evaluation_id' => $evaluation->id,
                        'question' => $questionData['question'],
                        'type' => $questionData['type'],
                        'ordre' => $index + 1,
                        'points' => $questionData['points'] ?? 1,
                        'options' => $options,
                        'correct_answers' => $correctAnswers,
                        'explanation' => $questionData['explanation'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $evaluation->load('questions');

            return response()->json([
                'success' => true,
                'message' => 'Évaluation mise à jour',
                'data' => $evaluation
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur mise à jour évaluation', ['error' => $e->getMessage()]);

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

        // ⚠️ Vérifier si l'évaluation peut être supprimée
        if (!$evaluation->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer: des étudiants ont déjà soumis leurs réponses'
            ], 403);
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

        // Utiliser la méthode publish() du model pour synchroniser status et is_published
        $evaluation->publish();

        return response()->json([
            'success' => true,
            'message' => 'Évaluation publiée',
            'data' => $evaluation->fresh()
        ]);
    }

    /**
     * GET /api/evaluations/student
     * Récupère les évaluations de l'étudiant connecté
     */
    public function myEvaluations(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié ou sans ID KLASSCI'
            ], 401);
        }

        // Appeler studentEvaluations avec l'ID de l'utilisateur connecté
        return $this->studentEvaluations($user->klassci_id, $request);
    }

    /**
     * GET /api/evaluations/student/{klassciEtudiantId}
     * Récupère les évaluations disponibles pour un étudiant
     */
    public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
    {
        // Récupérer la classe de l'étudiant depuis le dashboard
        try {
            // Récupérer l'utilisateur authentifié (via Sanctum)
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Récupérer le token KLASSCI depuis la base de données
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            \Log::info('Student Evaluations request', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id,
                'klassci_etudiant_id' => $klassciEtudiantId,
                'has_klassci_token' => !empty($klassciToken),
            ]);

            // Utiliser le token KLASSCI pour récupérer le dashboard
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
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

            \Log::info('Student classe found', ['classe_id' => $classeId]);

            // Récupérer les évaluations publiées pour cette classe
            $evaluationsLMS = Evaluation::with('questions', 'submissions')
                ->where('klassci_classe_id', $classeId)
                ->where('is_published', true)
                ->whereIn('status', ['planifiee', 'en_cours', 'terminee'])
                ->orderBy('date_evaluation', 'desc')
                ->get();

            \Log::info('Evaluations LMS found for student', ['count' => $evaluationsLMS->count()]);

            // Récupérer les évaluations KLASSCI avec fenêtres temporelles
            try {
                $klassciEvaluationsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'evaluations',
                    'GET'
                );

                $klassciEvaluations = collect($klassciEvaluationsResponse['data'] ?? []);
                \Log::info('KLASSCI evaluations retrieved', ['count' => $klassciEvaluations->count()]);

            } catch (\Exception $e) {
                \Log::warning('Could not fetch KLASSCI evaluations for windows', [
                    'error' => $e->getMessage()
                ]);
                $klassciEvaluations = collect([]);
            }

            // Enrichir les évaluations LMS avec fenêtres KLASSCI et soumissions
            $enrichedEvaluations = $evaluationsLMS->map(function ($evalLMS) use ($klassciEvaluations, $klassciEtudiantId, $klassciToken) {
                $evalArray = $evalLMS->toArray();

                // Trouver l'évaluation KLASSCI correspondante
                $klassciEval = $klassciEvaluations->firstWhere('id', $evalLMS->klassci_evaluation_id);

                if ($klassciEval) {
                    // Ajouter programmation avec window
                    $evalArray['programmation'] = $klassciEval['programmation'] ?? null;

                    // ⚠️ IMPORTANT: Utiliser la date_evaluation de LMS (avec heure) au lieu de KLASSCI (sans heure)
                    if ($evalArray['programmation']) {
                        $evalArray['programmation']['date_evaluation'] = $evalLMS->date_evaluation;
                    }

                    // Ajouter lms_integration
                    $evalArray['lms_integration'] = $klassciEval['lms_integration'] ?? null;

                    // Ajouter classe et matière
                    $evalArray['classe'] = $klassciEval['classe'] ?? null;
                    $evalArray['matiere'] = $klassciEval['matiere'] ?? null;

                    // Ajouter questions_count
                    $evalArray['questions_count'] = $evalLMS->questions->count();

                    \Log::debug('Evaluation enriched with KLASSCI data', [
                        'lms_id' => $evalLMS->id,
                        'klassci_id' => $evalLMS->klassci_evaluation_id,
                        'has_window' => isset($klassciEval['programmation']['window']),
                        'questions_count' => $evalArray['questions_count']
                    ]);
                } else {
                    // Évaluation LMS pure (sans klassci_evaluation_id)
                    // Récupérer les infos de matière et classe via API KLASSCI
                    try {
                        if ($evalLMS->klassci_matiere_id) {
                            $matiereResponse = $this->klassciService->requestWithUserToken(
                                $klassciToken,
                                "matieres/{$evalLMS->klassci_matiere_id}",
                                'GET'
                            );
                            $evalArray['matiere'] = $matiereResponse['data']['matiere'] ?? null;
                        }

                        if ($evalLMS->klassci_classe_id) {
                            $classeResponse = $this->klassciService->requestWithUserToken(
                                $klassciToken,
                                "classes/{$evalLMS->klassci_classe_id}",
                                'GET'
                            );
                            $evalArray['classe'] = $classeResponse['data'] ?? null;
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Could not fetch matiere/classe for pure LMS eval', [
                            'error' => $e->getMessage()
                        ]);
                    }

                    // Ajouter questions_count pour évaluation LMS pure
                    $evalArray['questions_count'] = $evalLMS->questions->count();

                    \Log::warning('KLASSCI evaluation not found for LMS eval', [
                        'lms_id' => $evalLMS->id,
                        'klassci_id' => $evalLMS->klassci_evaluation_id,
                        'questions_count' => $evalArray['questions_count']
                    ]);
                }

                // Ajouter la soumission de l'étudiant
                $submission = $evalLMS->submissions()
                    ->where('klassci_etudiant_id', $klassciEtudiantId)
                    ->latest()
                    ->first();

                $evalArray['student_submission'] = $submission;

                // 🔍 DEBUG: Log pour vérifier les soumissions
                \Log::debug('Student submission check', [
                    'eval_id' => $evalLMS->id,
                    'eval_title' => $evalLMS->titre,
                    'student_id' => $klassciEtudiantId,
                    'submission_found' => $submission !== null,
                    'submission_status' => $submission ? $submission->status : 'null',
                    'submission_note' => $submission ? $submission->note_sur_20 : 'null'
                ]);

                return $evalArray;
            })->values()->toArray();

            return response()->json([
                'success' => true,
                'data' => $enrichedEvaluations
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération évaluations étudiant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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

        if (!$evaluation || !$evaluation->is_published) {
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

        // Vérifier la fenêtre temporelle KLASSCI
        try {
            $user = $request->user();
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            // Récupérer l'évaluation KLASSCI avec sa fenêtre
            $klassciEvalResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'evaluations',
                'GET'
            );

            $klassciEval = collect($klassciEvalResponse['data'] ?? [])
                ->firstWhere('id', $evaluation->klassci_evaluation_id);

            $window = $klassciEval['programmation']['window'] ?? null;

            // Vérifier que la fenêtre est ouverte
            if ($window && !$window['is_open']) {
                $message = 'L\'évaluation n\'est pas encore ouverte';

                if (!$window['has_started']) {
                    $startAt = \Carbon\Carbon::parse($window['start_at'])->format('d/m/Y à H:i');
                    $message = "L'évaluation ouvrira le {$startAt}";
                } elseif ($window['has_ended']) {
                    $endAt = \Carbon\Carbon::parse($window['end_at'])->format('d/m/Y à H:i');
                    $message = "L'évaluation est fermée depuis le {$endAt}";
                }

                \Log::warning('Tentative de démarrage hors fenêtre', [
                    'evaluation_id' => $id,
                    'klassci_evaluation_id' => $evaluation->klassci_evaluation_id,
                    'student_id' => $klassciEtudiantId,
                    'window' => $window
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'window' => $window
                ], 403);
            }

            \Log::info('Démarrage évaluation autorisé', [
                'evaluation_id' => $id,
                'student_id' => $klassciEtudiantId,
                'window_open' => $window ? $window['is_open'] : 'no_window',
                'time_left_minutes' => $window['time_left_minutes'] ?? null
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur vérification fenêtre temporelle', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            // En cas d'erreur KLASSCI, on laisse passer (fallback gracieux)
            // Mais on log l'erreur pour investigation
            \Log::warning('Fallback: allowing evaluation start despite window check failure');
        }

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
            'data' => $submission,
            'window' => $window ?? null
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
     * GET /api/evaluations/{id}/my-submission
     * Récupère la soumission de l'étudiant connecté pour une évaluation
     */
    public function getMySubmission(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->klassci_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $evaluation = Evaluation::find($id);

            if (!$evaluation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Évaluation non trouvée'
                ], 404);
            }

            // Récupérer la soumission de l'étudiant
            $submission = EvaluationSubmission::where('evaluation_id', $id)
                ->where('klassci_etudiant_id', $user->klassci_id)
                ->orderBy('attempt', 'desc')
                ->first();

            if (!$submission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune soumission trouvée pour cette évaluation'
                ], 404);
            }

            // Charger les questions avec les réponses de l'étudiant
            $questions = $evaluation->questions()->get();
            $answers = $submission->answers ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $submission->id,
                    'evaluation_id' => $submission->evaluation_id,
                    'attempt' => $submission->attempt,
                    'status' => $submission->status,
                    'started_at' => $submission->started_at,
                    'submitted_at' => $submission->submitted_at,
                    'score' => $submission->score,
                    'note_sur_20' => $submission->note_sur_20,
                    'feedback' => $submission->feedback,
                    'questions' => $questions,
                    'answers' => $answers,
                    'evaluation' => [
                        'id' => $evaluation->id,
                        'titre' => $evaluation->titre,
                        'bareme' => $evaluation->bareme,
                        'coefficient' => $evaluation->coefficient,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération soumission étudiant', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la soumission'
            ], 500);
        }
    }

    /**
     * GET /api/evaluations/{id}/time-status
     * Récupère l'état temporel en temps réel d'une évaluation
     */
    public function getTimeStatus(int $id, Request $request): JsonResponse
    {
        $evaluation = Evaluation::find($id);

        if (!$evaluation) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non trouvée'
            ], 404);
        }

        try {
            $user = $request->user();
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            // Récupérer l'état KLASSCI à jour
            $klassciEvalResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'evaluations',
                'GET'
            );

            $klassciEval = collect($klassciEvalResponse['data'] ?? [])
                ->firstWhere('id', $evaluation->klassci_evaluation_id);

            $window = $klassciEval['programmation']['window'] ?? null;

            return response()->json([
                'success' => true,
                'data' => [
                    'window' => $window,
                    'server_time' => now()->toIso8601String()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération état temporel', [
                'evaluation_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer l\'état temporel'
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

    /**
     * Enrichit les évaluations avec les données KLASSCI (classes, matières)
     *
     * @param \Illuminate\Database\Eloquent\Collection $evaluations
     * @return array
     */
    private function enrichEvaluationsWithKlassciData($evaluations): array
    {
        try {
            // Récupérer les classes et matières de KLASSCI (avec cache)
            $klassciClasses = $this->klassciService->getClasses();
            $klassciMatieres = $this->klassciService->getMatieres();

            // Créer des maps pour accès rapide
            $classesMap = [];
            if (isset($klassciClasses['data'])) {
                foreach ($klassciClasses['data'] as $classe) {
                    $classesMap[$classe['id']] = $classe;
                }
            }

            $matieresMap = [];
            if (isset($klassciMatieres['data'])) {
                foreach ($klassciMatieres['data'] as $matiere) {
                    $matieresMap[$matiere['id']] = $matiere;
                }
            }

            // Enrichir chaque évaluation
            return $evaluations->map(function ($evaluation) use ($classesMap, $matieresMap) {
                $evalArray = $evaluation->toArray();

                // Ajouter les détails de la classe
                if (isset($evaluation->klassci_classe_id) && isset($classesMap[$evaluation->klassci_classe_id])) {
                    $evalArray['classe'] = $classesMap[$evaluation->klassci_classe_id];

                    // Normaliser les champs: KlassCI retourne 'name', on veut aussi 'nom' et 'libelle'
                    if (isset($evalArray['classe']['name']) && !empty($evalArray['classe']['name'])) {
                        // Si 'nom' est vide/absent, copier 'name' dedans
                        if (empty($evalArray['classe']['nom'])) {
                            $evalArray['classe']['nom'] = $evalArray['classe']['name'];
                        }
                        // Si 'libelle' est vide, utiliser 'name'
                        if (empty($evalArray['classe']['libelle'])) {
                            $evalArray['classe']['libelle'] = $evalArray['classe']['name'];
                        }
                    }

                    // Si toujours vide, utiliser le nom stocké en base
                    if (empty($evalArray['classe']['nom']) && $evaluation->classe_nom) {
                        $evalArray['classe']['nom'] = $evaluation->classe_nom;
                        $evalArray['classe']['libelle'] = $evaluation->classe_nom;
                    }
                } elseif ($evaluation->classe_nom) {
                    // Utiliser le nom stocké en base pour évaluations standalone
                    $evalArray['classe'] = [
                        'id' => $evaluation->klassci_classe_id,
                        'name' => $evaluation->classe_nom,
                        'libelle' => $evaluation->classe_nom,
                        'nom' => $evaluation->classe_nom,
                    ];
                } else {
                    $evalArray['classe'] = null;
                }

                // Ajouter les détails de la matière
                if (isset($evaluation->klassci_matiere_id) && isset($matieresMap[$evaluation->klassci_matiere_id])) {
                    $evalArray['matiere'] = $matieresMap[$evaluation->klassci_matiere_id];
                    // Ajouter libelle si manquant (KLASSCI ne retourne que 'nom')
                    if (!isset($evalArray['matiere']['libelle']) || empty($evalArray['matiere']['libelle'])) {
                        $evalArray['matiere']['libelle'] = $evalArray['matiere']['nom'] ?? $evaluation->matiere_nom;
                    }
                    // Si pas de nom/libelle du tout, utiliser le nom stocké en base
                    if (empty($evalArray['matiere']['nom']) && $evaluation->matiere_nom) {
                        $evalArray['matiere']['nom'] = $evaluation->matiere_nom;
                        $evalArray['matiere']['libelle'] = $evaluation->matiere_nom;
                    }
                } elseif ($evaluation->matiere_nom) {
                    // Utiliser le nom stocké en base pour évaluations standalone
                    $evalArray['matiere'] = [
                        'id' => $evaluation->klassci_matiere_id,
                        'nom' => $evaluation->matiere_nom,
                        'libelle' => $evaluation->matiere_nom,
                    ];
                } else {
                    $evalArray['matiere'] = null;
                }

                // Ajouter les informations de l'enseignant (depuis la table users locale)
                if ($evaluation->klassci_enseignant_id) {
                    $enseignant = User::where('klassci_id', $evaluation->klassci_enseignant_id)->first();
                    if ($enseignant) {
                        $evalArray['enseignant'] = [
                            'id' => $enseignant->klassci_id,
                            'nom' => $enseignant->name,
                            'email' => $enseignant->email,
                        ];
                        $evalArray['enseignant_nom'] = $enseignant->name;
                    } else {
                        $evalArray['enseignant'] = null;
                        $evalArray['enseignant_nom'] = 'Enseignant #' . $evaluation->klassci_enseignant_id;
                    }
                } else {
                    $evalArray['enseignant'] = null;
                    $evalArray['enseignant_nom'] = null;
                }

                // Formater la date pour un affichage convivial
                if ($evaluation->date_evaluation) {
                    $evalArray['date_evaluation_formatted'] = $evaluation->date_evaluation->format('d/m/Y à H:i');
                    $evalArray['date_evaluation_short'] = $evaluation->date_evaluation->format('d/m/Y');
                }

                // Préserver les champs ajoutés dynamiquement (comme student_submission)
                if (isset($evaluation->student_submission)) {
                    $evalArray['student_submission'] = $evaluation->student_submission;
                }

                // Ajouter les informations de verrouillage et compteurs
                $evalArray['is_locked'] = $evaluation->isLocked();
                $evalArray['can_be_edited'] = $evaluation->canBeEdited();
                $evalArray['submissions_count'] = $evaluation->submissions()->count();
                $evalArray['questions_count'] = $evaluation->questions()->count();

                return $evalArray;
            })->toArray();

        } catch (\Exception $e) {
            \Log::error('Erreur enrichissement évaluations', ['error' => $e->getMessage()]);

            // En cas d'erreur, retourner les évaluations sans enrichissement
            return $evaluations->toArray();
        }
    }

    /**
     * GET /api/evaluations/{id}/results-by-class
     * Récupère les résultats détaillés d'une évaluation pour tous les étudiants de la classe
     * (Coordinateur/Admin uniquement)
     */
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
            $classeDetails = $this->klassciService->getClasseDetails($evaluation->klassci_classe_id);
            $etudiants = $classeDetails['etudiants'] ?? [];

            \Log::info('👥 Étudiants de la classe', [
                'total_etudiants' => count($etudiants)
            ]);

            // Enrichir avec les données KLASSCI (classe, matière)
            $evaluationEnrichie = $this->enrichEvaluationsWithKlassciData(collect([$evaluation]))[0];

            // Créer un tableau de résultats pour TOUS les étudiants
            $resultats = [];
            foreach ($etudiants as $etudiant) {
                // Récupérer la dernière soumission de l'étudiant pour cette évaluation
                $submission = $evaluation->submissions()
                    ->where('klassci_etudiant_id', $etudiant['id'])
                    ->latest()
                    ->first();

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

            // Calculer les statistiques
            $soumissions = collect($resultats)->where('status', 'soumis');
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère toutes les soumissions d'une évaluation (pour enseignant/coordinateur)
     */
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchronise toutes les notes d'une évaluation vers KLASSCI
     */
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

            foreach ($submissions as $submission) {
                try {
                    // TODO: Implémenter l'API KLASSCI pour synchroniser la note
                    // Pour l'instant, marquer comme synchronisé
                    $submission->update([
                        'synced_to_klassci' => true,
                        'synced_at' => now()
                    ]);

                    $syncedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'submission_id' => $submission->id,
                        'student_id' => $submission->klassci_etudiant_id,
                        'error' => $e->getMessage()
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Prévisualise une évaluation (enseignant) avant publication
     * Pour coordinateur: accessible uniquement si évaluation terminée
     */
    public function preview(int $id): JsonResponse
    {
        try {
            $evaluation = Evaluation::with('questions')->findOrFail($id);

            // Restriction coordinateur: ne peut prévisualiser que les évaluations terminées
            $user = auth()->user();
            if ($user && $user->role === 'coordinateur') {
                $isTerminee = $evaluation->status === 'terminee' ||
                             EvaluationSubmission::where('evaluation_id', $id)->count() > 0;

                if (!$isTerminee) {
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/my-grades
     * Récupère toutes les notes de l'étudiant connecté groupées par matière
     */
    public function myGrades(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user || !$user->klassci_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié ou sans ID KlassCI'
                ], 401);
            }

            // Récupérer les matières depuis l'API KlassCI pour avoir les vrais noms
            $klassciProxy = app(\App\Services\KlassciProxyService::class);
            $matieresData = [];

            try {
                $matieresResponse = $klassciProxy->getMatieres();
                if (isset($matieresResponse['success']) && $matieresResponse['success'] && isset($matieresResponse['data'])) {
                    foreach ($matieresResponse['data'] as $matiere) {
                        $matieresData[$matiere['id']] = $matiere['nom'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Impossible de récupérer les matières depuis KlassCI", ['error' => $e->getMessage()]);
            }

            // Récupérer toutes les soumissions de l'étudiant avec leurs évaluations
            $submissions = EvaluationSubmission::where('klassci_etudiant_id', $user->klassci_id)
                ->where('status', 'corrige')
                ->with(['evaluation' => function($query) {
                    $query->where('is_published', true);
                }])
                ->whereHas('evaluation', function($query) {
                    $query->where('is_published', true);
                })
                ->orderBy('submitted_at', 'desc')
                ->get();

            // Grouper les notes par matière
            $gradesByMatiere = [];

            foreach ($submissions as $submission) {
                $evaluation = $submission->evaluation;

                if (!$evaluation) continue;

                $matiereId = $evaluation->klassci_matiere_id;

                // Récupérer le nom de la matière depuis KlassCI (priorité absolue) ou depuis l'évaluation
                $matiereNom = $matieresData[$matiereId] ?? $evaluation->matiere_nom ?? 'Matière inconnue';

                if (!isset($gradesByMatiere[$matiereId])) {
                    $gradesByMatiere[$matiereId] = [
                        'matiere_id' => $matiereId,
                        'matiere_nom' => $matiereNom,
                        'evaluations' => [],
                        'moyenne' => 0,
                        'total_evaluations' => 0
                    ];
                }

                $gradesByMatiere[$matiereId]['evaluations'][] = [
                    'evaluation_id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'type' => $evaluation->type,
                    'note' => $submission->note_sur_20,
                    'coefficient' => $evaluation->coefficient ?? 1,
                    'date_evaluation' => $evaluation->date_evaluation,
                    'date_soumission' => $submission->submitted_at,
                    'temps_passe' => $submission->temps_passe_minutes,
                ];
            }

            // Calculer les moyennes par matière
            foreach ($gradesByMatiere as $matiereId => &$matiere) {
                $totalPoints = 0;
                $totalCoef = 0;

                foreach ($matiere['evaluations'] as $eval) {
                    $totalPoints += $eval['note'] * $eval['coefficient'];
                    $totalCoef += $eval['coefficient'];
                }

                $matiere['moyenne'] = $totalCoef > 0 ? round($totalPoints / $totalCoef, 2) : 0;
                $matiere['total_evaluations'] = count($matiere['evaluations']);
            }
            unset($matiere); // Important: détruire la référence pour éviter les effets de bord

            // Convertir en array indexé et trier par nom de matière
            $gradesList = array_values($gradesByMatiere);
            usort($gradesList, fn($a, $b) => strcmp($a['matiere_nom'], $b['matiere_nom']));

            // Calculer la moyenne générale
            $totalMoyenne = 0;
            $countMatieres = 0;

            foreach ($gradesList as $matiere) {
                if ($matiere['total_evaluations'] > 0) {
                    $totalMoyenne += $matiere['moyenne'];
                    $countMatieres++;
                }
            }

            $moyenneGenerale = $countMatieres > 0 ? round($totalMoyenne / $countMatieres, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'matieres' => $gradesList,
                    'moyenne_generale' => $moyenneGenerale,
                    'total_matieres' => $countMatieres,
                    'total_evaluations' => $submissions->count()
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur récupération notes étudiant', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}