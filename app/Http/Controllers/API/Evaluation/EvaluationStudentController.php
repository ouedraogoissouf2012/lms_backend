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
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EvaluationStudentController — extrait verbatim de EvaluationController
 * dans le cadre du split god-controller (1676 lignes -> 5 fichiers SRP).
 *
 * Aucun changement comportemental : les méthodes sont déplacées telles
 * quelles, avec injection DI cohérente. Phase 2 du refactor identifié
 * dans le bilan d'audit post-PR #149.
 */
class EvaluationStudentController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private EvaluationEnrichmentService $enrichmentService,
        private EvaluationGradingService $gradingService,
    ) {}

    public function myEvaluations(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // $user is guaranteed non-null; check klassci_id sync (business invariant).
        if (!$user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur sans ID KLASSCI synchronisé'
            ], 401);
        }

        return $this->studentEvaluationsForUser($user, $request);
    }

    private function studentEvaluationsForUser(User $user, Request $request): JsonResponse
    {
        $klassciEtudiantId = $user->klassci_id;

        // Récupérer la classe de l'étudiant depuis le dashboard
        try {
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
            // Exclure celles qui n'ont aucune question (inutiles pour l'étudiant)
            $evaluationsLMS = Evaluation::with('questions', 'submissions')
                ->where('klassci_classe_id', $classeId)
                ->where('is_published', true)
                ->whereIn('status', ['planifiee', 'en_cours', 'terminee'])
                ->whereHas('questions') // Uniquement celles qui ont au moins 1 question
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

    public function startEvaluation(int $id, StartEvaluationRequest $request): JsonResponse
    {
        // Issue #123 : identité étudiant dérivée du token Sanctum, jamais lue du body.
        $user = $this->authenticatedUser($request);
        $klassciEtudiantId = $user->klassci_id;

        $evaluation = Evaluation::find($id);

        if (!$evaluation || !$evaluation->is_published) {
            return response()->json([
                'success' => false,
                'message' => 'Évaluation non disponible'
            ], 404);
        }

        // Vérifier si c'est un mode entraînement (évaluation terminée)
        $isPracticeMode = $evaluation->isTerminee();
        // NOTE: On ne bloque plus les évaluations terminées, l'étudiant peut s'entraîner

        // Vérifier que l'évaluation a des questions
        if ($evaluation->questions()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cette évaluation n\'a pas encore de questions.'
            ], 422);
        }

        // Vérifier la fenêtre temporelle KLASSCI
        try {
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

            // Vérifier que la fenêtre est ouverte (sauf en mode entraînement)
            if ($window && !$window['is_open'] && !$isPracticeMode) {
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

            // En mode entraînement, activer le flag
            if ($isPracticeMode) {
                \Log::info('Mode entraînement activé pour évaluation terminée', [
                    'evaluation_id' => $id,
                    'student_id' => $klassciEtudiantId
                ]);
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

        // Vérifier si l'étudiant a déjà une tentative en cours
        $activeSubmission = EvaluationSubmission::where('evaluation_id', $id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->where('status', 'en_cours')
            ->first();

        if ($activeSubmission) {
            // Retourner la soumission en cours au lieu d'en créer une nouvelle
            return response()->json([
                'success' => true,
                'message' => 'Reprise de la tentative en cours',
                'data' => $activeSubmission,
                'window' => $window ?? null
            ]);
        }

        // Vérifier le nombre de tentatives terminées
        $attemptsCount = EvaluationSubmission::where('evaluation_id', $id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->whereIn('status', ['soumis', 'corrige'])
            ->count();

        // En mode entraînement, ne pas bloquer sur max_attempts
        if (!$isPracticeMode && $evaluation->max_attempts && $attemptsCount >= $evaluation->max_attempts) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint (' . $evaluation->max_attempts . ')'
            ], 403);
        }

        // Créer une nouvelle soumission
        $submission = EvaluationSubmission::create([
            'evaluation_id' => $id,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'attempt' => $attemptsCount + 1,
            'status' => 'en_cours',
            'started_at' => now(),
            'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isPracticeMode ? 'Mode entraînement démarré' : 'Évaluation démarrée',
            'data' => $submission,
            'window' => $window ?? null,
            'is_practice' => $isPracticeMode
        ]);
    }

    public function submitEvaluation(int $id, SubmitEvaluationRequest $request): JsonResponse
    {
        // Authorization + validation handled by SubmitEvaluationRequest:
        // - Student only
        // - Evaluation published
        // - Deadline not passed
        // - Not already submitted
        // - Answers trimmed + validated

        try {
            $user = auth()->user();

            // Find the in-progress submission created by startEvaluation()
            // If it doesn't exist, create one (for test compatibility)
            $submission = EvaluationSubmission::where('evaluation_id', $id)
                ->where('student_id', $user->id)
                ->where('status', 'en_cours')
                ->first();

            if (!$submission) {
                // Create submission if it doesn't exist
                $submission = EvaluationSubmission::create([
                    'evaluation_id' => $id,
                    'student_id' => $user->id,
                    'klassci_etudiant_id' => $user->klassci_id,
                    'attempt' => 1,
                    'status' => 'en_cours',
                    'started_at' => now(),
                ]);
            }

            // Update submission with validated answers
            $submission->answers = $request->validated('answers');
            $this->gradingService->submit($submission); // Auto-calculate score

            return response()->json([
                'success' => true,
                'message' => 'Évaluation soumise avec succès',
                'data' => [
                    'submission' => $submission,
                    'score' => $submission->score,
                    'note_sur_20' => $submission->note_sur_20,
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erreur soumission évaluation', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission'
            ], 500);
        }
    }

    public function getMySubmission(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // $user guaranteed non-null; check klassci_id sync (business invariant).
            if (!$user->klassci_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur sans ID KLASSCI synchronisé'
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

            // Calculer si la correction est disponible (7 jours après soumission)
            $correctionDelayDays = 7;
            $submittedAt = $submission->submitted_at ? \Carbon\Carbon::parse($submission->submitted_at) : null;
            $correctionAvailable = $submittedAt && now()->diffInDays($submittedAt) >= $correctionDelayDays;
            $correctionAvailableAt = $submittedAt ? $submittedAt->copy()->addDays($correctionDelayDays)->toIso8601String() : null;

            // Si la correction n'est pas encore disponible, masquer les bonnes réponses
            $questionsData = $questions->map(function ($question) use ($correctionAvailable) {
                $q = $question->toArray();
                if (!$correctionAvailable) {
                    unset($q['correct_answers']);
                    unset($q['explanation']);
                }
                return $q;
            });

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
                    'questions' => $questionsData,
                    'answers' => $answers,
                    'correction_available' => $correctionAvailable,
                    'correction_available_at' => $correctionAvailableAt,
                    'correction_delay_days' => $correctionDelayDays,
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
            $user = $this->authenticatedUser($request);
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

    public function myGrades(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // $user guaranteed non-null; check klassci_id sync (business invariant).
            if (!$user->klassci_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur sans ID KlassCI synchronisé'
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
            // Exclure les soumissions d'entraînement (feedback commence par [PRACTICE])
            $submissions = EvaluationSubmission::where('klassci_etudiant_id', $user->klassci_id)
                ->where('status', 'corrige')
                ->where(function($q) {
                    $q->whereNull('feedback')
                      ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
                })
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
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
