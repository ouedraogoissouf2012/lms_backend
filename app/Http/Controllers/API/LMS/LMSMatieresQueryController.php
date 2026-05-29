<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LMSMatieresQueryController — extrait verbatim de LMSMatieresController.
 * Refactor du god-controller (774 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
final class LMSMatieresQueryController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * GET /api/lms/matieres/{id}
     * Retourne les détails complets d'une matière.
     *
     * Contenu retourné:
     * - Informations matière (nom, code, coefficient, heures)
     * - Combinaisons disponibles (toutes les paires filière+niveau)
     * - Enseignants assignés pour l'année courante
     * - Séances programmées (30 prochains jours)
     * - Évaluations programmées (enrichies avec quiz LMS)
     * - Lessons LMS
     * - Statistiques (nb séances, taux réalisation)
     */
    public function matiereDetails(int $matiereId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('Récupération détails matière', [
                'matiere_id' => $matiereId,
                'user_id' => $user->id
            ]);

            // 1. Récupérer les informations de base de la matière directement par ID
            try {
                $matiereResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiereId}",
                    'GET'
                );

                $matiereData = $matiereResponse['data'] ?? null;

                if (!$matiereData) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Matière non trouvée'
                    ], 404);
                }

                // KLASSCI retourne {data: {matiere: {...}, combinaisons: [...], ...}}
                // Extraire uniquement l'objet matière
                $matiere = $matiereData['matiere'] ?? $matiereData;

                Log::info('Structure matière KLASSCI', [
                    'has_matiere_key' => isset($matiereData['matiere']),
                    'matiere_nom' => $matiere['nom'] ?? 'N/A'
                ]);

            } catch (\Exception $e) {
                Log::error('Erreur récupération matière', [
                    'matiere_id' => $matiereId,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la récupération de la matière'
                ], 500);
            }

            // 2. Récupérer les combinaisons disponibles (filières + niveaux)
            $combinaisons = $matiereData['combinaisons'] ?? [];

            // 3. Récupérer les enseignants assignés à cette matière
            // Note: L'API KLASSCI peut retourner les enseignants dans matiere.enseignants ou matiereData.enseignants
            $enseignants = $matiereData['enseignants'] ?? $matiere['enseignants'] ?? [];

            // Si pas d'enseignants dans la matière, essayer de les récupérer via l'emploi du temps
            if (empty($enseignants)) {
                try {
                    $dateDebut = Carbon::now()->format('Y-m-d');
                    $dateFin = Carbon::now()->addDays(30)->format('Y-m-d');

                    $emploiTempsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "emploi-temps?matiere_id={$matiereId}&date_debut={$dateDebut}&date_fin={$dateFin}",
                        'GET'
                    );

                    /** @var array<int, array<string, mixed>> $seancesEmploi */
                    $seancesEmploi = $emploiTempsResponse['data'] ?? [];

                    // Extraire les enseignants uniques des séances
                    $enseignantsFromSeances = collect($seancesEmploi)
                        ->pluck('enseignant')
                        ->filter()
                        ->unique('id')
                        ->values();

                    $enseignants = $enseignantsFromSeances->toArray();
                } catch (\Exception $e) {
                    Log::warning('Erreur récupération enseignants via emploi du temps', [
                        'matiere_id' => $matiereId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 4. Récupérer les séances programmées selon le rôle (teacher-dashboard/student-dashboard/coordinateur)
            $seances = [];

            try {
                if ($user->isTeacher()) {
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/teacher-dashboard',
                        'GET'
                    );

                    /** @var array<int, array<string, mixed>> $matieresDashboard */
                    $matieresDashboard = $dashboard['data']['matieres'] ?? [];
                    $matiereInDashboard = collect($matieresDashboard)->firstWhere('id', $matiereId);

                    if ($matiereInDashboard) {
                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );
                        $seances = $matiereDetails['data']['seances_programmees'] ?? [];
                    }
                } elseif ($user->isStudent()) {
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/dashboard',
                        'GET'
                    );

                    /** @var array<int, array<string, mixed>> $matieresDashboard */
                    $matieresDashboard = $dashboard['data']['matieres'] ?? [];
                    $matiereInDashboard = collect($matieresDashboard)->firstWhere('id', $matiereId);

                    if ($matiereInDashboard) {
                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );
                        $seances = $matiereDetails['data']['seances_programmees'] ?? [];

                        if (!empty($seances)) {
                            Log::info('[DEBUG DATES] Séances récupérées de Klassci pour étudiant', [
                                'matiere_id' => $matiereId,
                                'user_id' => $user->id,
                                'count' => count($seances),
                                'premiere_seance' => $seances[0] ?? null,
                                'toutes_seances' => $seances
                            ]);
                        }
                    }
                } else {
                    // Coordinateur: utiliser les seances_programmees du payload matieres/{id}
                    $seances = $matiereData['seances_programmees'] ?? [];
                }
            } catch (\Exception $e) {
                Log::warning('Erreur récupération séances', [
                    'matiere_id' => $matiereId,
                    'user_role' => $user->role,
                    'error' => $e->getMessage()
                ]);
                $seances = [];
            }

            // 4b. Filtrer les séances masquées et archivées pour les étudiants
            if ($user->isStudent()) {
                /** @var array<int, array<string, mixed>> $seances */
                $seances = collect($seances)->filter(function (array $seance) use ($user): bool {
                    $seanceId = $seance['id'] ?? null;

                    if (!$seanceId) {
                        return true; // Garder si pas d'ID
                    }

                    // Trouver la séance locale correspondante
                    $localSeance = \App\Models\Seance::where('klassci_seance_id', $seanceId)
                        ->orWhere('id', $seanceId)
                        ->first();

                    if (!$localSeance) {
                        return true; // Garder si pas de séance locale (séance KLASSCI pure)
                    }

                    // Filtrer si archivée
                    if (!$localSeance->is_active) {
                        return false;
                    }

                    // Filtrer si masquée par l'étudiant
                    if (\App\Models\SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                        return false;
                    }

                    return true;
                })->values()->toArray();

                Log::info('Séances filtrées pour étudiant', [
                    'user_id' => $user->id,
                    'count_after_filter' => count($seances)
                ]);
            }

            // 5. Récupérer les évaluations programmées pour cette matière
            try {
                $evaluationsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'evaluations',
                    'GET'
                );

                /** @var array<int, array<string, mixed>> $evaluationsData */
                $evaluationsData = $evaluationsResponse['data'] ?? [];
                $evaluations = collect($evaluationsData)->filter(function (array $eval) use ($matiereId): bool {
                    $matiereData = $eval['matiere'] ?? null;
                    return is_array($matiereData) && isset($matiereData['id']) && $matiereData['id'] === $matiereId;
                })->values();
            } catch (\Exception $e) {
                Log::warning('Erreur récupération évaluations', [
                    'matiere_id' => $matiereId,
                    'error' => $e->getMessage()
                ]);
                $evaluations = collect();
            }

            // 5b. Enrichir les évaluations KLASSCI avec les quiz LMS
            $evaluationsEnrichies = $evaluations->map(function (array $eval) use ($user): array {
                $klassciEvaluationId = $eval['id'] ?? null;

                $quizLMS = null;
                if ($klassciEvaluationId) {
                    $quizLMS = \App\Models\Evaluation::where('klassci_evaluation_id', $klassciEvaluationId)->first();
                }

                $evalArray = $eval;
                $evalArray['has_online'] = $quizLMS !== null;

                if ($quizLMS) {
                    $evalArray['online_version'] = [
                        'id' => $quizLMS->id,
                        'status' => $quizLMS->status,
                        'is_published' => $quizLMS->is_published,
                        'is_locked' => $quizLMS->isLocked(),
                        'can_be_edited' => $quizLMS->canBeEdited(),
                        'questions_count' => $quizLMS->questions()->count(),
                        'submissions_count' => $quizLMS->submissions()->count(),
                    ];

                    if ($user->klassci_id) {
                        $submission = $quizLMS->submissions()
                            ->where('klassci_etudiant_id', $user->klassci_id)
                            ->latest()
                            ->first();
                        $evalArray['student_submission'] = $submission;
                    } else {
                        $evalArray['student_submission'] = null;
                    }
                } else {
                    $evalArray['online_version'] = null;
                    $evalArray['student_submission'] = null;
                }

                return $evalArray;
            })->all();

            // 5c. Ajouter aussi les évaluations LMS pures (sans klassci_evaluation_id)
            // PERF-03 batch 2 — Avant : 1 query main + 3×N sub-queries par éval
            //   ($eval->questions()->count, $eval->submissions()->count, $eval->submissions()->latest()).
            //   Pour 10 évals : 31 queries.
            // Après : 1 main + 2 (`withCount`) + 1 (batch user submissions) = **4 queries**.
            $evaluationsLMSPuresModels = \App\Models\Evaluation::where('klassci_matiere_id', $matiereId)
                ->whereNull('klassci_evaluation_id')
                ->withCount(['questions', 'submissions'])
                ->get();

            // Récupère en une seule passe la dernière submission du user pour CHAQUE éval.
            $userLatestSubmissions = collect();
            if ($user->klassci_id && $evaluationsLMSPuresModels->isNotEmpty()) {
                $userLatestSubmissions = \App\Models\EvaluationSubmission::query()
                    ->whereIn('evaluation_id', $evaluationsLMSPuresModels->pluck('id'))
                    ->where('klassci_etudiant_id', $user->klassci_id)
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('evaluation_id')
                    ->map(fn ($subs) => $subs->first()); // latest par éval
            }

            $evaluationsLMSPures = $evaluationsLMSPuresModels->map(function ($eval) use ($userLatestSubmissions): array {
                return [
                    'id' => 'lms_' . $eval->id,
                    'lms_id' => $eval->id,
                    'titre' => $eval->titre,
                    'description' => $eval->description,
                    'type' => 'lms_pure',
                    'matiere' => null,
                    'classe' => null,
                    'programmation' => [
                        'date_evaluation' => $eval->date_evaluation,
                        'duree_minutes' => $eval->duree_minutes,
                        'coefficient' => $eval->coefficient,
                        'bareme' => $eval->bareme,
                    ],
                    'has_online' => true,
                    'online_version' => [
                        'id' => $eval->id,
                        'status' => $eval->status,
                        'is_published' => $eval->is_published,
                        'is_locked' => $eval->isLocked(),
                        'can_be_edited' => $eval->canBeEdited(),
                        'questions_count' => $eval->questions_count,
                        'submissions_count' => $eval->submissions_count,
                    ],
                    'student_submission' => $userLatestSubmissions->get($eval->id),
                ];
            })->all();

            // Fusionner les évaluations KLASSCI enrichies et les LMS pures
            $evaluationsEnrichies = array_merge($evaluationsEnrichies, $evaluationsLMSPures);

            // 6. Récupérer les Lessons LMS pour cette matière
            $lessons = [];
            try {
                $query = \App\Models\Lesson::where('matiere_id', $matiereId);

                // Étudiants : leçons publiées uniquement ; enseignants/coordinateurs : toutes
                if ($user->isStudent()) {
                    $query->published();
                }

                // PERF-03 — eager load `progress` filtré au user courant.
                // Avant : 1 query/lesson via `progressForUser()` → N+1 sur la liste matière.
                // Après : 1 query agrégée qui jointure le LessonProgress du user à toutes les lessons.
                if ($user->isStudent()) {
                    $query->with(['progress' => function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    }]);
                }

                $lessons = $query->ordered()
                    ->get()
                    ->map(function ($lesson) use ($user): array {
                        $lessonArray = $lesson->toArray();

                        if ($user->isStudent()) {
                            // `progress` est filtré au user courant via l'eager load ci-dessus,
                            // donc la collection contient 0 ou 1 élément.
                            $lessonArray['user_progress'] = $lesson->progress->first();
                        }

                        return $lessonArray;
                    })->all();

                Log::info('Lessons LMS récupérés', [
                    'matiere_id' => $matiereId,
                    'count' => count($lessons)
                ]);

            } catch (\Exception $e) {
                Log::warning('Erreur récupération lessons LMS', [
                    'matiere_id' => $matiereId,
                    'error' => $e->getMessage()
                ]);
            }

            // 7. Enrichir les séances avec données visio, enseignant et effectif classe
            /** @var array<int, array<string, mixed>> $seances */
            $seancesEnrichies = collect($seances)->map(function (array $seance) use ($klassciToken): array {
                // Récupérer info visio depuis notre BDD
                $visioData = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

                // NE PAS ÉCRASER l'enseignant déjà présent dans la séance
                $seanceEnrichie = $seance;

                // Ajouter effectif de la classe
                if (isset($seance['classe']['id'])) {
                    try {
                        $classeDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "classes/{$seance['classe']['id']}",
                            'GET'
                        );
                        $seanceEnrichie['classe_effectif'] = $classeDetails['data']['classe']['places_occupees'] ?? 0;
                    } catch (\Exception $e) {
                        $seanceEnrichie['classe_effectif'] = 0;
                    }
                } else {
                    $seanceEnrichie['classe_effectif'] = 0;
                }

                // Ajouter données visio
                if ($visioData) {
                    $seanceEnrichie['visio_enabled'] = $visioData->visio_enabled;
                    $seanceEnrichie['visio_type'] = $visioData->visio_type;
                    $seanceEnrichie['visio_status'] = $visioData->visio_status;
                    $seanceEnrichie['visio_active'] = $visioData->visio_active;
                    $seanceEnrichie['visio_room_id'] = $visioData->visio_room_id;
                    $seanceEnrichie['visio_participants_count'] = $visioData->current_participants_count ?? 0;
                } else {
                    $seanceEnrichie['visio_enabled'] = false;
                    $seanceEnrichie['visio_type'] = null;
                    $seanceEnrichie['visio_status'] = null;
                    $seanceEnrichie['visio_active'] = false;
                    $seanceEnrichie['visio_room_id'] = null;
                    $seanceEnrichie['visio_participants_count'] = 0;
                }

                return $seanceEnrichie;
            })->all();

            // 8. Calculer des statistiques
            /** @var array<int, array<string, mixed>> $seances */
            $seancesCollection = collect($seances);
            $seancesRealisees = $seancesCollection->filter(function (array $seance): bool {
                return isset($seance['statut']) && $seance['statut'] === 'realise';
            });

            $stats = [
                'nombre_seances_programmees' => count($seances),
                'nombre_seances_realisees' => count($seancesRealisees),
                'taux_realisation' => count($seances) > 0
                    ? round((count($seancesRealisees) / count($seances)) * 100, 2)
                    : 0,
                'nombre_evaluations' => count($evaluations),
                'nombre_enseignants' => count($enseignants),
                'nombre_combinaisons' => count($combinaisons),
                'nombre_lessons' => count($lessons),
                'volume_horaire_total' => $matiere['volume_horaire_total'] ?? null,
                'coefficient' => $matiere['coefficient'] ?? null
            ];

            $response = [
                'success' => true,
                'data' => [
                    'matiere' => $matiere,
                    'combinaisons' => $combinaisons,
                    'enseignants' => $enseignants,
                    'lessons' => $lessons,
                    'seances_programmees' => $seancesEnrichies,
                    'evaluations_programmees' => $evaluationsEnrichies,
                    'statistiques' => $stats
                ]
            ];

            Log::info('✅ Matière details response', [
                'matiere_id' => $matiereId,
                'has_matiere' => !empty($matiere),
                'lessons_count' => count($lessons),
                'seances_count' => count($seances),
                'evaluations_count' => count($evaluationsEnrichies)
            ]);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Erreur récupération détails matière', [
                'matiere_id' => $matiereId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la matière',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/teacher/my-matieres
     * Récupérer les matières de l'enseignant connecté avec statistiques enrichies.
     *
     * Retourne les matières avec:
     * - Données KLASSCI (nom, coefficient, heures, classes)
     * - Nombre de lessons créées localement (table lessons)
     * - Nombre de séances programmées (KLASSCI)
     * - Nombre d'évaluations programmées (KLASSCI)
     */
    public function myMatieres(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('MyMatieres request', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id
            ]);

            // 1. Récupérer données KLASSCI (matières, séances, évaluations)
            $klassciData = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/teacher-dashboard',
                'GET'
            );

            /** @var array<int, array<string, mixed>> $matieres */
            $matieres = $klassciData['data']['matieres'] ?? [];
            /** @var array<int, array<string, mixed>> $evaluations */
            $evaluations = $klassciData['data']['evaluations'] ?? [];

            // 2. Enrichir chaque matière avec données LMS locales
            $matieresEnrichies = array_map(function (array $matiere) use ($evaluations): array {
                $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? null;

                if (!$matiereId) {
                    return $matiere;
                }

                // Compter TOUTES les lessons publiées pour cette matière (tous enseignants, sans soft deleted)
                $nombreLessonsPubliees = \App\Models\Lesson::where('matiere_id', $matiereId)
                    ->published()
                    ->count();

                // Compter TOUS les brouillons pour cette matière (tous enseignants, sans soft deleted)
                $nombreLessonsBrouillons = \App\Models\Lesson::where('matiere_id', $matiereId)
                    ->where('status', 'draft')
                    ->count();

                // Compter séances pour cette matière depuis la BDD locale (plus fiable que KLASSCI)
                $nombreSeances = \App\Models\Seance::where('klassci_matiere_id', $matiereId)->count();

                // Compter évaluations pour cette matière
                $nombreEvaluations = collect($evaluations)->filter(function (array $evaluation) use ($matiereId): bool {
                    $matiereData = $evaluation['matiere'] ?? null;
                    $evalMatiereId = is_array($matiereData)
                        ? ($matiereData['id'] ?? $matiereData['matiere_id'] ?? null)
                        : ($evaluation['matiere_id'] ?? null);
                    return $evalMatiereId == $matiereId;
                })->count();

                // Ajouter statistiques
                $matiere['statistiques'] = [
                    'nombre_lessons_publiees' => $nombreLessonsPubliees,
                    'nombre_lessons_brouillons' => $nombreLessonsBrouillons,
                    'nombre_seances' => $nombreSeances,
                    'nombre_evaluations' => $nombreEvaluations
                ];

                return $matiere;
            }, $matieres);

            Log::info('MyMatieres enrichies', [
                'nombre_matieres' => count($matieresEnrichies)
            ]);

            return response()->json([
                'success' => true,
                'data' => $matieresEnrichies
            ]);

        } catch (\Exception $e) {
            // §1.2 — Détail technique loggé server-side, message générique au client.
            Log::error('Erreur myMatieres', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des matières.',
            ], 500);
        }
    }
}
