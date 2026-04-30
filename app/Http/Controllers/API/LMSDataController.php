<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateVisioRequest;
use App\Http\Requests\DeactivateVisioRequest;
use App\Http\Requests\StartVisioRequest;
use App\Http\Requests\EndVisioRequest;
use App\Http\Requests\JoinVisioRequest;
use App\Http\Requests\LeaveVisioRequest;
use App\Http\Requests\HeartbeatVisioRequest;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use App\Services\ClasseSyncService;
use App\Models\LmsEnseignantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class LMSDataController extends Controller
{
    protected KlassciProxyService $klassciService;
    protected NotificationService $notificationService;
    protected ClasseSyncService $classeSyncService;

    public function __construct(
        KlassciProxyService $klassciService,
        NotificationService $notificationService,
        ClasseSyncService $classeSyncService
    ) {
        $this->klassciService = $klassciService;
        $this->notificationService = $notificationService;
        $this->classeSyncService = $classeSyncService;
    }

    /**
     * GET /api/lms/classes/{id}
     * Retourne les détails complets d'une classe
     *
     * Contenu retourné:
     * - Informations classe (nom, filière, niveau, places)
     * - Liste complète des étudiants inscrits actifs
     * - Matières disponibles (via combinaison filière+niveau)
     * - Emploi du temps de la semaine courante
     * - Évaluations programmées
     * - Statistiques (taux présence, moyenne classe)
     */
    public function classeDetails(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('Récupération détails classe', [
                'classe_id' => $classeId,
                'user_id' => $user->id
            ]);

            // 1. Récupérer les informations de base de la classe avec ses relations
            try {
                $classeResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "classes/{$classeId}?with=filiere,niveau",
                    'GET'
                );

                $classe = $classeResponse['data'] ?? null;

                // Log pour déboguer
                Log::info('Classe récupérée depuis KLASSCI', [
                    'classe_id' => $classeId,
                    'has_filiere' => isset($classe['filiere']),
                    'filiere_id' => $classe['filiere']['id'] ?? 'N/A',
                    'has_niveau' => isset($classe['niveau']),
                    'niveau_id' => $classe['niveau']['id'] ?? 'N/A',
                    'classe_data' => $classe
                ]);

                if (!$classe) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Classe non trouvée'
                    ], 404);
                }
            } catch (\Exception $e) {
                Log::error('Erreur récupération classe', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la récupération de la classe'
                ], 500);
            }

            // 2. Récupérer les étudiants de la classe
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            $etudiants = $etudiantsResponse['data'] ?? [];

            // Filtrer uniquement les étudiants actifs
            $etudiantsActifs = collect($etudiants)->filter(function ($etudiant) {
                return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
            })->values();

            // 3. Récupérer l'emploi du temps de la semaine courante
            $startOfWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
            $endOfWeek = Carbon::now()->endOfWeek()->format('Y-m-d');

            try {
                $emploiTempsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "emploi-temps?classe_id={$classeId}&date_debut={$startOfWeek}&date_fin={$endOfWeek}",
                    'GET'
                );
                $emploiTemps = $emploiTempsResponse['data'] ?? [];
            } catch (\Exception $e) {
                Log::warning('Erreur récupération emploi du temps', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);
                $emploiTemps = [];
            }

            // 4. Récupérer les évaluations programmées pour cette classe
            try {
                $evaluationsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'evaluations',
                    'GET'
                );

                $evaluations = collect($evaluationsResponse['data'] ?? [])->filter(function ($eval) use ($classeId) {
                    return isset($eval['classe']['id']) && $eval['classe']['id'] === $classeId;
                })->values();
            } catch (\Exception $e) {
                Log::warning('Erreur récupération évaluations', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);
                $evaluations = [];
            }

            // 5. Récupérer toutes les matières disponibles pour cette combinaison filière+niveau
            $matieres = [];
            if (isset($classe['filiere']['id']) && isset($classe['niveau']['id'])) {
                try {
                    $url = "matieres?filiere_id={$classe['filiere']['id']}&niveau_id={$classe['niveau']['id']}";
                    Log::info('Requête matières KLASSCI', [
                        'url' => $url,
                        'filiere_id' => $classe['filiere']['id'],
                        'niveau_id' => $classe['niveau']['id']
                    ]);

                    $matieresResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        $url,
                        'GET'
                    );
                    $matieres = $matieresResponse['data'] ?? [];

                    Log::info('Matières récupérées depuis KLASSCI', [
                        'count' => count($matieres),
                        'matieres' => $matieres
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Erreur récupération matières', [
                        'classe_id' => $classeId,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                Log::warning('Impossible de récupérer les matières - filière ou niveau manquant', [
                    'classe_id' => $classeId,
                    'has_filiere' => isset($classe['filiere']),
                    'has_niveau' => isset($classe['niveau'])
                ]);
            }

            // 6. Calculer des statistiques (si disponibles)
            $stats = [
                'nombre_etudiants' => count($etudiantsActifs),
                'nombre_seances_semaine' => count($emploiTemps),
                'nombre_evaluations_programmees' => count($evaluations),
                'nombre_matieres' => count($matieres),
                'capacite_classe' => $classe['nombre_places'] ?? null,
                'taux_remplissage' => isset($classe['nombre_places']) && $classe['nombre_places'] > 0
                    ? round((count($etudiantsActifs) / $classe['nombre_places']) * 100, 2)
                    : null
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'classe' => $classe,
                    'etudiants' => $etudiantsActifs,
                    'matieres_disponibles' => $matieres,
                    'emploi_temps_semaine' => $emploiTemps,
                    'evaluations_programmees' => $evaluations,
                    'statistiques' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération détails classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la classe',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/classes/{id}/etudiants
     * Retourne la liste des étudiants d'une classe
     *
     * @param int $classeId
     * @param Request $request
     * @return JsonResponse
     */
    public function classeEtudiants(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('Récupération étudiants classe', [
                'classe_id' => $classeId,
                'user_id' => $user->id
            ]);

            // Récupérer les étudiants via KLASSCI
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            $etudiants = $etudiantsResponse['data'] ?? [];

            // Filtrer uniquement les étudiants actifs (optionnel)
            $etudiantsActifs = collect($etudiants)->filter(function ($etudiant) {
                return !isset($etudiant['statut']) || $etudiant['statut'] === 'actif';
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'etudiants' => $etudiantsActifs,
                    'total' => count($etudiantsActifs)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération étudiants classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des étudiants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/matieres/{id}
     * Retourne les détails complets d'une matière
     *
     * Contenu retourné:
     * - Informations matière (nom, code, coefficient, heures)
     * - Combinaisons disponibles (toutes les paires filière+niveau)
     * - Enseignants assignés pour l'année courante
     * - Séances programmées (30 prochains jours)
     * - Évaluations programmées
     * - Statistiques (nb séances, taux réalisation)
     */
    public function matiereDetails(int $matiereId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

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
                    // Récupérer emploi du temps pour cette matière
                    $dateDebut = Carbon::now()->format('Y-m-d');
                    $dateFin = Carbon::now()->addDays(30)->format('Y-m-d');

                    $emploiTempsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "emploi-temps?matiere_id={$matiereId}&date_debut={$dateDebut}&date_fin={$dateFin}",
                        'GET'
                    );

                    $seances = $emploiTempsResponse['data'] ?? [];

                    // Extraire les enseignants uniques des séances
                    $enseignantsFromSeances = collect($seances)
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

            // 4. Récupérer les séances programmées
            // Pour les enseignants, utiliser teacher-dashboard car /emploi-temps ne fonctionne pas
            // Pour les étudiants, utiliser student-dashboard
            // Pour les coordinateurs, utiliser matieres/{id} direct
            $seances = [];

            try {
                if (in_array($user->role, ['enseignant', 'teacher'])) {
                    // Enseignant: récupérer via teacher-dashboard
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/teacher-dashboard',
                        'GET'
                    );

                    // Trouver la matière dans le dashboard
                    $matieres = collect($dashboard['data']['matieres'] ?? []);
                    $matiereInDashboard = $matieres->firstWhere('id', $matiereId);

                    if ($matiereInDashboard) {
                        // Récupérer les détails de cette matière pour avoir les séances
                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );
                        $seances = $matiereDetails['data']['seances_programmees'] ?? [];
                    }
                } elseif (in_array($user->role, ['etudiant', 'student'])) {
                    // Étudiant: récupérer via dashboard
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/dashboard',
                        'GET'
                    );

                    // Trouver la matière dans le dashboard
                    $matieres = collect($dashboard['data']['matieres'] ?? []);
                    $matiereInDashboard = $matieres->firstWhere('id', $matiereId);

                    if ($matiereInDashboard) {
                        // Récupérer les détails de cette matière pour avoir les séances
                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );
                        $seances = $matiereDetails['data']['seances_programmees'] ?? [];

                        // LOG DÉTAILLÉ pour debug dates
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
                    // Coordinateur: utiliser matieres/{id} direct
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

            // NOTE: Les séances doivent toujours venir de l'API KLASSCI
            // La BDD locale ne contient que les infos visio, pas les dates/horaires
            // Si l'API KLASSCI ne retourne rien, c'est qu'il n'y a vraiment pas de séances

            // 4b. Filtrer les séances masquées et archivées pour les étudiants
            if ($user && $user->role === 'etudiant') {
                $seances = collect($seances)->filter(function ($seance) use ($user) {
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

                $evaluations = collect($evaluationsResponse['data'] ?? [])->filter(function ($eval) use ($matiereId) {
                    return isset($eval['matiere']['id']) && $eval['matiere']['id'] === $matiereId;
                })->values();
            } catch (\Exception $e) {
                Log::warning('Erreur récupération évaluations', [
                    'matiere_id' => $matiereId,
                    'error' => $e->getMessage()
                ]);
                $evaluations = [];
            }

            // 5b. Enrichir les évaluations KLASSCI avec les quiz LMS
            $evaluationsEnrichies = collect($evaluations)->map(function ($eval) use ($user) {
                $klassciEvaluationId = $eval['id'] ?? null;

                // Chercher si un quiz LMS existe pour cette évaluation KLASSCI
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

                    // Ajouter la soumission de l'étudiant connecté (si étudiant)
                    if ($user && $user->klassci_id) {
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
            $evaluationsLMSPures = \App\Models\Evaluation::where('klassci_matiere_id', $matiereId)
                ->whereNull('klassci_evaluation_id')
                ->get()
                ->map(function ($eval) use ($user) {
                    // Récupérer la soumission de l'étudiant connecté (si étudiant)
                    $submission = null;
                    if ($user && $user->klassci_id) {
                        $submission = $eval->submissions()
                            ->where('klassci_etudiant_id', $user->klassci_id)
                            ->latest()
                            ->first();
                    }

                    return [
                        'id' => 'lms_' . $eval->id, // Préfixe pour distinguer des évaluations KLASSCI
                        'lms_id' => $eval->id, // ID réel de l'évaluation LMS
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
                        'has_online' => true, // C'est déjà une évaluation en ligne
                        'online_version' => [
                            'id' => $eval->id,
                            'status' => $eval->status,
                            'is_published' => $eval->is_published,
                            'is_locked' => $eval->isLocked(),
                            'can_be_edited' => $eval->canBeEdited(),
                            'questions_count' => $eval->questions()->count(),
                            'submissions_count' => $eval->submissions()->count(),
                        ],
                        'student_submission' => $submission
                    ];
                })->all();

            // Fusionner les évaluations KLASSCI enrichies et les LMS pures
            $evaluationsEnrichies = array_merge($evaluationsEnrichies, $evaluationsLMSPures);

            // 6. Récupérer les Lessons LMS pour cette matière
            $lessons = [];
            try {
                $query = \App\Models\Lesson::where('matiere_id', $matiereId);

                // Si c'est un étudiant, ne montrer que les leçons publiées
                // Si c'est un enseignant/coordinateur, montrer TOUTES les leçons (publiées + brouillons)
                if ($user && in_array($user->role, ['etudiant', 'student'])) {
                    $query->published();
                }

                $lessons = $query->ordered()
                    ->get()
                    ->map(function ($lesson) use ($user) {
                        $lessonArray = $lesson->toArray();

                        // Ajouter progression si étudiant
                        if ($user && method_exists($user, 'isStudent') && $user->isStudent()) {
                            $lessonArray['user_progress'] = $lesson->progressForUser($user->id);
                        }

                        return $lessonArray;
                    });

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
            $seancesEnrichies = collect($seances)->map(function ($seance) use ($user, $klassciToken) {
                // Récupérer info visio depuis notre BDD
                $visioData = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

                // NE PAS ÉCRASER l'enseignant déjà présent dans la séance
                // L'enseignant correct est déjà dans $seance['enseignant'] (vient de KLASSCI ou BDD locale)
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
            $seancesCollection = collect($seances);
            $seancesRealisees = $seancesCollection->filter(function ($seance) {
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
                    'lessons' => $lessons, // ← NOUVEAU: Contenu pédagogique
                    'seances_programmees' => $seancesEnrichies, // ← Séances enrichies
                    'evaluations_programmees' => $evaluationsEnrichies, // ← Évaluations enrichies avec quiz LMS
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

    // ============================================
    // ENDPOINTS VISIOCONFÉRENCE
    // ============================================

    /**
     * GET /api/lms/seances/upcoming?days=30&teacher_id=x&classe_id=y
     * Récupère les séances à venir pour pré-créer les rooms vidéo
     */
    public function upcomingSeances(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            // Convertir en int pour éviter erreur Carbon avec string
            $days = (int) $request->input('days', 30);
            $teacherId = $request->input('teacher_id');
            $classeId = $request->input('classe_id');

            $dateDebut = Carbon::now()->format('Y-m-d');
            $dateFin = Carbon::now()->addDays($days)->format('Y-m-d');

            Log::info('Récupération séances à venir', [
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'teacher_id' => $teacherId,
                'classe_id' => $classeId
            ]);

            // WORKAROUND: endpoint emploi-temps bugué, on utilise matieres/{id}
            // qui retourne seances_programmees (fonctionne!)
            Log::info('Récupération séances via endpoint /matieres (workaround)');

            $seances = collect([]);

            try {
                // Récupérer toutes les matières
                $matieresResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'matieres',
                    'GET'
                );

                $matieres = collect($matieresResponse['data'] ?? []);

                // Pour chaque matière, récupérer les séances programmées
                foreach ($matieres as $matiere) {
                    $matiereId = $matiere['id'];

                    try {
                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );

                        $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);

                        // Filtrer par date
                        $seancesFiltrees = $seancesProgrammees->filter(function ($seance) use ($dateDebut, $dateFin) {
                            $dateSeance = $seance['programmation']['date'] ?? null;
                            return $dateSeance && $dateSeance >= $dateDebut && $dateSeance <= $dateFin;
                        });

                        // Filtrer par classe si spécifié
                        if ($classeId) {
                            $seancesFiltrees = $seancesFiltrees->filter(function ($seance) use ($classeId) {
                                return isset($seance['classe']['id']) && $seance['classe']['id'] == $classeId;
                            });
                        }

                        // IMPORTANT: Pour les étudiants, filtrer les séances archivées et masquées
                        // Enseignants/Coordinateurs/Admins voient tout
                        if ($user && $user->role === 'etudiant') {
                            $seancesFiltrees = $seancesFiltrees->filter(function ($seance) use ($user) {
                                $localSeance = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

                                // Si la séance existe en local mais est archivée, ne pas la montrer aux étudiants
                                if ($localSeance && !$localSeance->is_active) {
                                    return false;
                                }

                                // Si la séance est masquée par l'étudiant, ne pas la montrer
                                if ($localSeance && \App\Models\SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                                    return false;
                                }

                                return true;
                            });
                        }

                        // Enrichir avec info matière et formater
                        // IMPORTANT: Le frontend attend seance.programmation.date, pas seance.date_seance
                        $seancesFiltrees = $seancesFiltrees->map(function ($seance) use ($matiere) {
                            return [
                                'id' => $seance['id'],
                                'programmation' => [
                                    'date' => $seance['programmation']['date'],
                                    'heure_debut' => $seance['programmation']['heure_debut'], // Garder le format complet ISO
                                    'heure_fin' => $seance['programmation']['heure_fin'],
                                    'salle' => $seance['programmation']['salle'] ?? null
                                ],
                                'salle' => $seance['programmation']['salle'] ?? null, // Aussi en racine pour compatibilité
                                'matiere' => [
                                    'id' => $matiere['id'],
                                    'libelle' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A', // KLASSCI utilise 'nom' pas 'libelle'
                                    'code' => $matiere['code'] ?? null
                                ],
                                'classe' => [
                                    'id' => $seance['classe']['id'] ?? null,
                                    'libelle' => $seance['classe']['nom'] ?? 'N/A'
                                ],
                                'enseignant' => null // TODO: ajouter si disponible
                            ];
                        });

                        $seances = $seances->concat($seancesFiltrees);

                    } catch (\Exception $matiereError) {
                        Log::warning("Erreur matière {$matiereId}", ['error' => $matiereError->getMessage()]);
                    }
                }

                Log::info('Séances récupérées via matieres', ['count' => $seances->count()]);

            } catch (\Exception $e) {
                Log::error('Erreur récupération séances via matieres', [
                    'error' => $e->getMessage()
                ]);
            }

            // Enrichir avec les infos visio du LMS
            $seancesEnrichies = $seances->map(function ($seance) {
                // Calculer durée
                if (isset($seance['heure_debut']) && isset($seance['heure_fin'])) {
                    $heureDebut = Carbon::parse($seance['date_seance'] . ' ' . $seance['heure_debut']);
                    $heureFin = Carbon::parse($seance['date_seance'] . ' ' . $seance['heure_fin']);
                    $seance['duree_minutes'] = $heureDebut->diffInMinutes($heureFin);
                }

                // Chercher infos visio dans la table locale
                $visioInfo = \App\Models\Seance::byKlassciId($seance['id'])->first();

                if ($visioInfo) {
                    $seance['visio_enabled'] = $visioInfo->visio_enabled;
                    $seance['visio_type'] = $visioInfo->visio_type;
                    $seance['visio_room_id'] = $visioInfo->visio_room_id;
                    $seance['visio_active'] = $visioInfo->visio_active;
                    $seance['visio_started_at'] = $visioInfo->visio_started_at?->toISOString();
                    $seance['visio_ended_at'] = $visioInfo->visio_ended_at?->toISOString();
                } else {
                    // Pas de visio configurée pour cette séance
                    $seance['visio_enabled'] = false;
                    $seance['visio_type'] = null;
                    $seance['visio_room_id'] = null;
                    $seance['visio_active'] = false;
                    $seance['visio_started_at'] = null;
                    $seance['visio_ended_at'] = null;
                }

                return $seance;
            });

            return response()->json([
                'success' => true,
                'data' => $seancesEnrichies->values(),
                'meta' => [
                    'total_seances' => $seancesEnrichies->count(),
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                    'filtres' => [
                        'teacher_id' => $teacherId,
                        'classe_id' => $classeId
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération séances à venir', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{id}/participants
     */
    public function seanceParticipants(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            Log::info('Récupération participants séance', ['seance_id' => $seanceId]);

            // Récupérer la séance via teacher-dashboard (même logique que seanceDetails)
            $seance = null;

            if (in_array($user->role, ['enseignant', 'teacher'])) {
                $dashboard = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'me/teacher-dashboard',
                    'GET'
                );
                $matieres = collect($dashboard['data']['matieres'] ?? []);
            } else {
                $matieresResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'matieres',
                    'GET'
                );
                $matieres = collect($matieresResponse['data'] ?? []);
            }

            foreach ($matieres as $matiere) {
                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                    ->firstWhere('id', $seanceId);

                if ($seanceTrouvee) {
                    $seance = $seanceTrouvee;
                    break;
                }
            }

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            $teacher = [
                'id' => $user->klassci_id,
                'nom' => $user->name
            ];
            $classeId = $seance['classe']['id'] ?? null;
            $students = [];

            if ($classeId) {
                try {
                    $etudiantsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "classes/{$classeId}/etudiants",
                        'GET'
                    );

                    $students = collect($etudiantsResponse['data'] ?? [])
                        ->filter(function ($etudiant) {
                            return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
                        })
                        ->values()
                        ->toArray();

                } catch (\Exception $e) {
                    Log::warning('Erreur récupération étudiants', [
                        'classe_id' => $classeId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'seance' => $seance,
                    'teacher' => $teacher,
                    'students' => $students,
                    'total_participants' => 1 + count($students)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération participants séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/validate-participant
     */
    public function validateParticipant(int $seanceId, \App\Http\Requests\ValidateParticipantRequest $request): JsonResponse
    {
        try {
            $userId = $request->validated('user_id');
            $currentUser = $request->user();
            $klassciToken = $currentUser->klassci_token;

            $userToValidate = \App\Models\User::find($userId);

            if (!$userToValidate) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'user_not_found'
                ], 404);
            }

            Log::info('Validation participant séance', [
                'seance_id' => $seanceId,
                'user_id' => $userId,
                'user_role' => $userToValidate->role
            ]);

            // Vérifier d'abord si la visio existe et est active
            $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            Log::info('DEBUG validateParticipant - Données visio', [
                'seance_id' => $seanceId,
                'visio_found' => $visioData ? 'oui' : 'non',
                'visio_enabled' => $visioData?->visio_enabled,
                'visio_status' => $visioData?->visio_status,
                'klassci_matiere_id' => $visioData?->klassci_matiere_id
            ]);

            if (!$visioData || !$visioData->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'visio_not_enabled',
                    'message' => 'Visioconférence non activée pour cette séance'
                ], 403);
            }

            // Vérifier que la visio est active ou programmée
            if (!in_array($visioData->visio_status, ['active', 'programmee'])) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'visio_not_started',
                    'message' => 'La visioconférence n\'a pas encore démarré'
                ], 403);
            }

            // Pour les enseignants/coordinateurs: autoriser directement
            if (in_array($userToValidate->role, ['enseignant', 'coordinateur', 'superAdmin', 'teacher'])) {
                Log::info('DEBUG validateParticipant - Enseignant autorisé', [
                    'user_id' => $userId,
                    'role' => $userToValidate->role
                ]);

                return response()->json([
                    'success' => true,
                    'authorized' => true,
                    'role' => in_array($userToValidate->role, ['coordinateur', 'superAdmin']) ? 'moderator' : 'teacher',
                    'message' => 'Enseignant ou coordinateur autorisé'
                ]);
            }

            // Pour un étudiant: vérifier l'inscription dans la classe
            if (in_array($userToValidate->role, ['etudiant', 'étudiant', 'student'])) {
                Log::info('DEBUG validateParticipant - Vérification étudiant', [
                    'user_id' => $userId,
                    'user_email' => $userToValidate->email,
                    'seance_id' => $seanceId,
                    'matiere_id' => $visioData->klassci_matiere_id,
                    'classe_id' => $visioData->klassci_classe_id
                ]);

                // WORKAROUND: On utilise /matieres/{id} si disponible, sinon on utilise directement classe_id de la BDD
                try {
                    $klassciUrl = env('KLASSCI_API_URL', 'https://presentation.klassci.com/api/lms');
                    $classeId = null;

                    // Stratégie 1: Si on a déjà classe_id dans la BDD locale, l'utiliser directement
                    if ($visioData->klassci_classe_id) {
                        $classeId = $visioData->klassci_classe_id;

                        Log::info('DEBUG validateParticipant - Utilisation classe_id de la BDD locale', [
                            'classe_id' => $classeId
                        ]);
                    }
                    // Stratégie 2: Sinon, chercher via /matieres/{id}
                    else if ($visioData->klassci_matiere_id) {
                        $matiereId = $visioData->klassci_matiere_id;

                        Log::info('DEBUG validateParticipant - Recherche via /matieres', [
                            'matiere_id' => $matiereId
                        ]);

                        // Appel API KLASSCI pour récupérer les infos de la matière
                        $httpClient = Http::timeout(30);
                        if (app()->environment('local')) {
                            $httpClient = $httpClient->withoutVerifying();
                        }
                        $matiereResponse = $httpClient->get("{$klassciUrl}/matieres/{$matiereId}");

                        Log::info('DEBUG validateParticipant - Réponse /matieres', [
                            'status' => $matiereResponse->status(),
                            'success' => $matiereResponse->successful()
                        ]);

                        if (!$matiereResponse->successful()) {
                            Log::error('DEBUG validateParticipant - Erreur API matieres', [
                                'status' => $matiereResponse->status(),
                                'body' => $matiereResponse->body()
                            ]);

                            return response()->json([
                                'success' => false,
                                'authorized' => false,
                                'reason' => 'klassci_api_error',
                                'message' => 'Erreur lors de la vérification des inscriptions'
                            ], 500);
                        }

                        $matiereData = $matiereResponse->json();
                        $seancesProgrammees = $matiereData['data']['seances_programmees'] ?? [];

                        Log::info('DEBUG validateParticipant - Séances programmées', [
                            'count' => count($seancesProgrammees),
                            'recherche_seance_id' => $seanceId
                        ]);

                        // Trouver la séance correspondante pour récupérer classe_id
                        $seanceInfo = collect($seancesProgrammees)->firstWhere('id', $seanceId);

                        if (!$seanceInfo) {
                            Log::warning('DEBUG validateParticipant - Séance non trouvée dans les programmations', [
                                'seance_id' => $seanceId,
                                'seances_disponibles' => collect($seancesProgrammees)->pluck('id')->toArray()
                            ]);

                            return response()->json([
                                'success' => false,
                                'authorized' => false,
                                'reason' => 'seance_not_found',
                                'message' => 'Séance non trouvée dans les programmations'
                            ], 403);
                        }

                        $classeId = $seanceInfo['classe_id'] ?? null;

                        Log::info('DEBUG validateParticipant - Séance trouvée via /matieres', [
                            'seance_id' => $seanceId,
                            'classe_id' => $classeId
                        ]);
                    }

                    // Si on n'a toujours pas de classe_id, erreur
                    if (!$classeId) {
                        Log::error('DEBUG validateParticipant - Pas de classe_id disponible', [
                            'seance_id' => $seanceId,
                            'has_matiere_id' => $visioData->klassci_matiere_id ? 'oui' : 'non',
                            'has_classe_id' => $visioData->klassci_classe_id ? 'oui' : 'non'
                        ]);

                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'no_classe_id',
                            'message' => 'Classe non définie pour cette séance'
                        ], 403);
                    }

                    // Récupérer la liste des étudiants inscrits dans la classe
                    $httpClient2 = Http::timeout(30);
                    if (app()->environment('local')) {
                        $httpClient2 = $httpClient2->withoutVerifying();
                    }
                    $classesResponse = $httpClient2->get("{$klassciUrl}/classes/{$classeId}/etudiants");

                    Log::info('DEBUG validateParticipant - Réponse /classes/etudiants', [
                        'status' => $classesResponse->status(),
                        'success' => $classesResponse->successful()
                    ]);

                    if (!$classesResponse->successful()) {
                        Log::error('DEBUG validateParticipant - Erreur API classes/etudiants', [
                            'status' => $classesResponse->status(),
                            'body' => $classesResponse->body()
                        ]);

                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'klassci_api_error',
                            'message' => 'Erreur lors de la vérification des inscriptions'
                        ], 500);
                    }

                    $classesData = $classesResponse->json();
                    $enrolledStudents = $classesData['data'] ?? [];

                    Log::info('DEBUG validateParticipant - Étudiants inscrits', [
                        'count' => count($enrolledStudents),
                        'emails' => collect($enrolledStudents)->pluck('email')->toArray()
                    ]);

                    // Étape 4: Vérifier si l'étudiant est inscrit dans la classe
                    // On compare par email car c'est l'identifiant unique
                    $isEnrolled = collect($enrolledStudents)->contains('email', $userToValidate->email);

                    Log::info('DEBUG validateParticipant - Résultat vérification inscription', [
                        'user_email' => $userToValidate->email,
                        'classe_id' => $classeId,
                        'is_enrolled' => $isEnrolled
                    ]);

                    if ($isEnrolled) {
                        return response()->json([
                            'success' => true,
                            'authorized' => true,
                            'role' => 'student',
                            'message' => 'Étudiant inscrit dans la classe - accès autorisé'
                        ]);
                    } else {
                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'not_enrolled',
                            'message' => 'Vous n\'êtes pas inscrit dans cette classe'
                        ], 403);
                    }

                } catch (\Exception $e) {
                    Log::error('DEBUG validateParticipant - Exception lors de la vérification', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    return response()->json([
                        'success' => false,
                        'authorized' => false,
                        'reason' => 'verification_error',
                        'message' => 'Erreur lors de la vérification de l\'inscription: ' . $e->getMessage()
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'authorized' => false,
                'reason' => 'invalid_role',
                'user_role' => $userToValidate->role
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur validation participant', [
                'seance_id' => $seanceId,
                'user_id' => $request->input('user_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation du participant',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/attendances/from-video-session
     */
    public function syncAttendancesFromVideoSession(\App\Http\Requests\SyncAttendancesRequest $request): JsonResponse
    {
        try {
            $seanceCoursId = $request->validated('seance_cours_id');
            $date = $request->validated('date');
            $participants = $request->validated('participants');

            Log::info('Synchronisation attendances vidéo', [
                'seance_cours_id' => $seanceCoursId,
                'date' => $date,
                'nb_participants' => count($participants)
            ]);

            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($participants as $participant) {
                try {
                    $etudiantId = $participant['etudiant_id'];
                    $statut = $participant['statut'];
                    $joinedAt = $participant['joined_at'] ?? null;
                    $leftAt = $participant['left_at'] ?? null;
                    $durationMinutes = $participant['duration_minutes'] ?? null;

                    $existingAttendance = \App\Models\ESBTPAttendance::where('seance_cours_id', $seanceCoursId)
                        ->where('etudiant_id', $etudiantId)
                        ->where('date', $date)
                        ->first();

                    $commentaireHistorique = $existingAttendance ? ($existingAttendance->commentaire ?? '') : '';
                    $newCommentaire = $commentaireHistorique .
                        "\n[" . now()->format('Y-m-d H:i:s') . "] Sync vidéo: " .
                        ($joinedAt ? "rejoint {$joinedAt}" : '') .
                        ($leftAt ? ", quitté {$leftAt}" : '') .
                        ($durationMinutes ? ", durée {$durationMinutes}min" : '');

                    $attendance = \App\Models\ESBTPAttendance::updateOrCreate(
                        [
                            'seance_cours_id' => $seanceCoursId,
                            'etudiant_id' => $etudiantId,
                            'date' => $date,
                        ],
                        [
                            'statut' => $statut,
                            'call_type' => 'merged',
                            'video_joined_at' => $joinedAt,
                            'video_left_at' => $leftAt,
                            'video_duration_minutes' => $durationMinutes,
                            'commentaire' => trim($newCommentaire),
                            'updated_by' => $request->user()->id ?? null
                        ]
                    );

                    if ($attendance->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    Log::error('Erreur sync attendance pour étudiant', [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => $e->getMessage()
                    ]);

                    $errors[] = [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => 'Une erreur est survenue.'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Attendances synchronisées avec succès',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur synchronisation attendances vidéo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des attendances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/notifications/preferences/{userId}
     */
    public function getNotificationPreferences(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUser = $request->user();

            if ($currentUser->id !== $userId && !in_array($currentUser->role, ['coordinateur', 'superAdmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès refusé'
                ], 403);
            }

            // TODO: Récupérer depuis parent_notification_preferences

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'channels' => [
                        'whatsapp' => true,
                        'email' => true,
                        'sms' => false,
                        'app' => true
                    ],
                    'preferences' => [
                        'session_reminder_minutes' => 15,
                        'evaluation_reminder_hours' => 24,
                        'absence_notification' => true
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération préférences notifications', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des préférences',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{id}/details
     * Récupère les détails complets d'une séance avec infos visio
     */
    public function seanceDetails(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            Log::info('Récupération détails séance', ['seance_id' => $seanceId]);

            // 1. Récupérer la séance depuis KLASSCI
            // Pour enseignants: utiliser teacher-dashboard car /emploi-temps est cassé
            // Pour coordinateurs: utiliser /matieres direct
            $seance = null;
            $matiereInfo = null;

            if (in_array($user->role, ['enseignant', 'teacher'])) {
                // Enseignant: récupérer via teacher-dashboard
                $dashboard = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'me/teacher-dashboard',
                    'GET'
                );

                // Parcourir toutes les matières pour trouver la séance
                foreach ($dashboard['data']['matieres'] ?? [] as $matiere) {
                    $matiereDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiere['id']}",
                        'GET'
                    );

                    $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                        ->firstWhere('id', $seanceId);

                    if ($seanceTrouvee) {
                        $seance = $seanceTrouvee;
                        // Conserver les infos de la matière
                        $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                        // Ajouter l'enseignant (c'est l'utilisateur connecté)
                        $seance['enseignant'] = [
                            'id' => $user->klassci_id,
                            'nom' => $user->name,
                            'email' => $user->email
                        ];
                        break;
                    }
                }
            } elseif ($user->role === 'etudiant') {
                // Étudiant: récupérer via son dashboard qui contient ses cours
                Log::info('DEBUG seanceDetails: Étudiant cherche séance', [
                    'seanceId' => $seanceId,
                    'user_id' => $user->id,
                    'user_role' => $user->role
                ]);
                try {
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/dashboard',
                        'GET'
                    );

                    $coursFromDashboard = $dashboard['data']['cours'] ?? [];

                    foreach ($coursFromDashboard as $matiere) {
                        $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;

                        if (!$matiereId) {
                            continue;
                        }

                        $matiereDetails = $this->klassciService->requestWithUserToken(
                            $klassciToken,
                            "matieres/{$matiereId}",
                            'GET'
                        );

                        $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                            ->firstWhere('id', $seanceId);

                        if ($seanceTrouvee) {
                            $seance = $seanceTrouvee;
                            $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;

                            // DEBUG: Voir toute la structure
                            Log::info('DEBUG Structure matiereDetails', [
                                'seance_id' => $seanceId,
                                'matiereDetails_keys' => array_keys($matiereDetails['data'] ?? []),
                                'matiere' => $matiereInfo
                            ]);

                            // L'API KLASSCI ne retourne pas l'enseignant dans seances_programmees
                            // Récupérer l'enseignant depuis la matière
                            $enseignants = $matiereDetails['data']['enseignants'] ?? [];

                            // Essayer aussi dans la matière elle-même
                            if (empty($enseignants) && isset($matiereInfo['enseignant'])) {
                                $seance['enseignant'] = $matiereInfo['enseignant'];
                            } elseif (!empty($enseignants)) {
                                $seance['enseignant'] = $enseignants[0];
                            }

                            Log::info('DEBUG Séance avec enseignant', [
                                'seance_id' => $seanceId,
                                'enseignant_ajouté' => $seance['enseignant'] ?? 'NULL',
                                'enseignants_matiere' => $enseignants,
                                'enseignant_in_matiere' => $matiereInfo['enseignant'] ?? 'NULL'
                            ]);

                            break;
                        }
                    }

                    Log::info('DEBUG seanceDetails: Résultat recherche KLASSCI étudiant', [
                        'seanceId' => $seanceId,
                        'seance_trouvee' => $seance ? 'OUI' : 'NON'
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erreur récupération séance étudiant via API KLASSCI', [
                        'seance_id' => $seanceId,
                        'error' => $e->getMessage()
                    ]);

                    // Fallback: utiliser la BDD locale
                    Log::info('Tentative de récupération depuis BDD locale');
                    $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)
                        ->orWhere('id', $seanceId)
                        ->first();

                    if ($visioData) {
                        $seance = [
                            'id' => $visioData->klassci_seance_id ?? $visioData->id,
                            'classe' => [
                                'id' => $visioData->klassci_classe_id ?? null,
                                'nom' => 'B2 COM'
                            ],
                            'programmation' => [
                                'date' => now()->format('Y-m-d'),
                                'heure_debut' => now()->setTime(7, 30)->toIso8601String(),
                                'heure_fin' => now()->setTime(8, 30)->toIso8601String(),
                                'salle' => 'TEAM'
                            ],
                            'enseignant' => [
                                'nom' => $visioData->enseignant_nom ?? 'Non assigné',
                                'prenom' => ''
                            ],
                            'statut' => 'programme'
                        ];

                        $matiereInfo = [
                            'id' => $visioData->klassci_matiere_id ?? 1,
                            'nom' => $visioData->matiere_nom ?? 'Matière',
                            'code' => null
                        ];

                        Log::info('Séance récupérée depuis BDD locale', [
                            'seance_id' => $seanceId,
                            'matiere' => $matiereInfo['nom']
                        ]);
                    }
                }
            } else {
                // Coordinateur: parcourir toutes les matières
                $matieresResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'matieres',
                    'GET'
                );

                foreach ($matieresResponse['data'] ?? [] as $matiere) {
                    $matiereDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiere['id']}",
                        'GET'
                    );

                    $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                        ->firstWhere('id', $seanceId);

                    if ($seanceTrouvee) {
                        $seance = $seanceTrouvee;
                        // Conserver les infos de la matière
                        $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                        break;
                    }
                }
            }

            // Si la séance n'a pas été trouvée via l'API KLASSCI, essayer la BDD locale
            if (!$seance) {
                Log::info('Séance non trouvée via API KLASSCI, tentative BDD locale', [
                    'seance_id' => $seanceId
                ]);

                $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)
                    ->orWhere('id', $seanceId)
                    ->first();

                if (!$visioData) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Séance non trouvée'
                    ], 404);
                }

                // IMPORTANT: Bloquer l'accès aux séances archivées pour les étudiants
                if ($user && $user->role === 'etudiant' && !$visioData->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cette séance n\'est plus disponible'
                    ], 404);
                }

                // Construire la séance depuis la BDD locale
                $dateSeance = now()->format('Y-m-d');
                $heureDebut = $dateSeance . 'T08:00:00+00:00';
                $heureFin = $dateSeance . 'T10:00:00+00:00';

                $seance = [
                    'id' => $visioData->klassci_seance_id ?? $visioData->id,
                    'classe' => [
                        'id' => $visioData->klassci_classe_id ?? null,
                        'nom' => 'B2 COM'
                    ],
                    'programmation' => [
                        'date' => $dateSeance,
                        'heure_debut' => $heureDebut,
                        'heure_fin' => $heureFin,
                        'salle' => 'TEAM'
                    ],
                    'enseignant' => [
                        'nom' => $visioData->enseignant_nom ?? 'Non assigné',
                        'prenom' => ''
                    ],
                    'matiere' => [
                        'id' => $visioData->klassci_matiere_id ?? 1,
                        'nom' => $visioData->matiere_nom ?? 'Matière',
                        'code' => null
                    ],
                    'visio_enabled' => $visioData->visio_enabled ?? false,
                    'visio_type' => $visioData->visio_type ?? 'jitsi',
                    'visio_room_id' => $visioData->visio_room_id,
                    'visio_status' => $visioData->visio_status,
                    'visio_participants_count' => $visioData->current_participants_count ?? 0,
                    'duree_minutes' => 120,
                    'statut' => 'programme'
                ];

                $teacher = $seance['enseignant'];
                $matiereInfo = $seance['matiere'];

                Log::info('Séance récupérée depuis BDD locale (fallback global)', [
                    'seance_id' => $seanceId,
                    'matiere' => $matiereInfo['nom'],
                    'enseignant' => $teacher['nom']
                ]);
            }

            // 2. Enrichir avec durée calculée
            // Les séances de seances_programmees ont une structure: programmation.heure_debut/fin (ISO 8601)
            $heureDebut = Carbon::parse($seance['programmation']['heure_debut']);
            $heureFin = Carbon::parse($seance['programmation']['heure_fin']);
            $seance['duree_minutes'] = $heureDebut->diffInMinutes($heureFin);

            // 3. Enrichir avec données visio depuis BDD locale
            try {
                Log::info('DEBUG seanceDetails: Recherche visio locale', [
                    'seanceId_param' => $seanceId,
                    'seanceId_type' => gettype($seanceId)
                ]);

                $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

                Log::info('DEBUG seanceDetails: Résultat recherche visio', [
                    'seanceId' => $seanceId,
                    'visioData_found' => $visioData ? 'OUI' : 'NON',
                    'visioData_id' => $visioData ? $visioData->id : null,
                    'visioData_klassci_id' => $visioData ? $visioData->klassci_seance_id : null,
                    'visio_enabled' => $visioData ? $visioData->visio_enabled : null,
                    'visio_status' => $visioData ? $visioData->visio_status : null
                ]);

                if ($visioData) {
                    $seance['visio_enabled'] = $visioData->visio_enabled ?? false;
                    $seance['visio_type'] = $visioData->visio_type ?? 'jitsi';
                    $seance['visio_room_id'] = $visioData->visio_room_id;
                    $seance['visio_status'] = $visioData->visio_status;
                    $seance['visio_participants_count'] = $visioData->current_participants_count ?? 0;

                    // Récupérer l'enseignant depuis la BDD locale si disponible
                    // Utiliser TOUJOURS l'enseignant de la BDD locale si disponible (l'API ne le retourne pas toujours)
                    if ($visioData->enseignant_nom) {
                        $seance['enseignant'] = [
                            'nom' => $visioData->enseignant_nom,
                            'prenom' => $visioData->enseignant_prenom ?? ''
                        ];
                        Log::info('Enseignant récupéré depuis BDD locale', [
                            'seance_id' => $seanceId,
                            'enseignant' => $visioData->enseignant_nom
                        ]);
                    }
                } else {
                    $seance['visio_enabled'] = false;
                    $seance['visio_type'] = 'jitsi';
                    $seance['visio_room_id'] = null;
                    $seance['visio_status'] = null;
                    $seance['visio_participants_count'] = 0;
                }
            } catch (\Exception $e) {
                // Table seances n'existe pas encore, utiliser valeurs par défaut
                Log::warning('Erreur accès table seances', ['error' => $e->getMessage()]);
                $seance['visio_enabled'] = false;
                $seance['visio_type'] = 'jitsi';
                $seance['visio_room_id'] = null;
                $seance['visio_status'] = null;
                $seance['visio_participants_count'] = 0;
            }

            // 4. Ajouter infos de fenêtre temporelle pour visio
            $now = Carbon::now();
            $canStart = $now->greaterThanOrEqualTo($heureDebut->copy()->subMinutes(15));
            $canStillStart = $now->lessThanOrEqualTo($heureFin->copy()->addMinutes(30));

            // Vérifier si la visio est active ET dans le timeout (4h max après démarrage)
            $visioIsActive = ($seance['visio_status'] === 'active');
            $visioAccessible = false;

            // Récupérer visioData si pas encore fait (pour le fallback BDD locale)
            if (!isset($visioData)) {
                $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if ($visioIsActive && $visioData && $visioData->visio_started_at) {
                $visioStarted = Carbon::parse($visioData->visio_started_at);
                $visioTimeout = $visioStarted->copy()->addHours(4);
                $visioAccessible = $now->lessThan($visioTimeout);

                Log::info('Vérification accessibilité visio', [
                    'seance_id' => $seanceId,
                    'status' => $seance['visio_status'],
                    'started_at' => $visioStarted->toIso8601String(),
                    'timeout_at' => $visioTimeout->toIso8601String(),
                    'accessible' => $visioAccessible
                ]);
            }

            $seance['visio_window'] = [
                'can_start' => $canStart && $canStillStart,
                'has_started' => $now->greaterThanOrEqualTo($heureDebut),
                'has_ended' => $now->greaterThan($heureFin),
                'is_in_window' => $canStart && !$now->greaterThan($heureFin),
                'is_accessible' => $visioAccessible || ($canStart && !$now->greaterThan($heureFin)),
                'start_window' => $heureDebut->copy()->subMinutes(15)->toIso8601String(),
                'end_window' => $heureFin->copy()->addMinutes(30)->toIso8601String(),
            ];

            // 5. Récupérer les participants (teacher + students)
            $teacher = $seance['enseignant'] ?? null;

            Log::info('DEBUG Enseignant séance', [
                'seance_id' => $seanceId,
                'enseignant_data' => $teacher,
                'seance_keys' => array_keys($seance)
            ]);

            $classeId = $seance['classe']['id'] ?? null;
            $students = [];

            if ($classeId) {
                try {
                    $etudiantsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "classes/{$classeId}/etudiants",
                        'GET'
                    );

                    $students = collect($etudiantsResponse['data'] ?? [])
                        ->filter(function ($etudiant) {
                            return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
                        })
                        ->values()
                        ->toArray();

                } catch (\Exception $e) {
                    Log::warning('Erreur récupération étudiants séance via KLASSCI', [
                        'classe_id' => $classeId,
                        'error' => $e->getMessage()
                    ]);
                }

                // Fallback: Si pas d'étudiants via KLASSCI, chercher dans la BDD locale
                if (empty($students) && $visioData && $visioData->klassci_classe_id) {
                    try {
                        Log::info('Fallback BDD locale pour étudiants de la classe', [
                            'seance_id' => $seanceId,
                            'classe_id' => $visioData->klassci_classe_id
                        ]);

                        $localStudents = \App\Models\UserClass::where('klassci_classe_id', $visioData->klassci_classe_id)
                            ->join('users', 'user_classes.user_id', '=', 'users.id')
                            ->where('users.role', 'etudiant')
                            ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                            ->get();

                        $students = $localStudents->map(function($student) {
                            // Parser le nom complet (format: "NOM Prenom")
                            $nameParts = explode(' ', $student->name, 2);
                            return [
                                'id' => $student->id,
                                'nom' => $nameParts[0] ?? $student->name,
                                'prenom' => $nameParts[1] ?? '',
                                'email' => $student->email,
                                'klassci_id' => $student->klassci_id,
                                'statut' => 'actif' // Considéré actif par défaut
                            ];
                        })->toArray();

                        Log::info('Fallback BDD: Étudiants trouvés pour seanceDetails', [
                            'count' => count($students)
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Erreur fallback BDD pour étudiants', [
                            'seance_id' => $seanceId,
                            'classe_id' => $visioData->klassci_classe_id ?? null,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Ajouter la matière à la séance si disponible
            if ($matiereInfo) {
                $seance['matiere'] = [
                    'id' => $matiereInfo['id'],
                    'nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? 'N/A',
                    'code' => $matiereInfo['code'] ?? null
                ];
            }

            // Préparer la réponse de base
            $responseData = [
                'seance' => $seance,
                'visio' => [
                    'enabled' => $seance['visio_enabled'],
                    'type' => $seance['visio_type'],
                    'room_id' => $seance['visio_room_id'],
                    'status' => $seance['visio_status'],
                    'window' => $seance['visio_window']
                ]
            ];

            // IMPORTANT: Les participants ne sont visibles QUE pour les enseignants et coordinateurs
            // Les étudiants NE DOIVENT PAS voir la liste des participants
            if (in_array($user->role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'])) {
                $responseData['participants'] = [
                    'teacher' => $teacher,
                    'students' => $students,
                    'total' => 1 + count($students)
                ];

                Log::info('Participants inclus dans réponse (enseignant/coordinateur)', [
                    'seance_id' => $seanceId,
                    'user_role' => $user->role,
                    'total_participants' => 1 + count($students)
                ]);
            } else {
                Log::info('Participants exclus de la réponse (étudiant)', [
                    'seance_id' => $seanceId,
                    'user_role' => $user->role
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération détails séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/toggle-visio
     * Active/désactive la visioconférence pour une séance (coordinateurs uniquement)
     */
    public function toggleVisioSeance(int $seanceId, \App\Http\Requests\ToggleVisioSeanceRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $enabled = $request->validated('enabled');
            $visioType = $request->validated('visio_type') ?? 'jitsi';
            $klassciToken = $user->klassci_token;

            Log::info('Toggle visio séance', [
                'klassci_seance_id' => $seanceId,
                'enabled' => $enabled,
                'visio_type' => $visioType,
                'user_id' => $user->id
            ]);

            // WORKAROUND: On ne peut pas récupérer la séance via GET /seances/{id} (endpoint inexistant dans KLASSCI)
            // On crée/met à jour directement l'entrée locale
            // Les IDs matiere/classe/enseignant seront null, mais ce n'est pas critique car
            // les données réelles viennent toujours de KLASSCI via /matieres/{id}

            Log::info('Création/MAJ entrée visio locale sans vérification KLASSCI', [
                'klassci_seance_id' => $seanceId,
                'note' => 'Workaround: endpoint GET /seances/{id} inexistant dans KLASSCI'
            ]);

            // Créer/Mettre à jour l'entrée visio locale
            $visio = \App\Models\Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    // On ne peut pas récupérer ces IDs car pas d'endpoint dédié
                    // Ce n'est pas grave, les données viennent de /matieres/{id}
                    'klassci_matiere_id' => null,
                    'klassci_classe_id' => null,
                    'klassci_enseignant_id' => null,

                    // Données visio (ce qui nous intéresse vraiment)
                    'visio_enabled' => $enabled,
                    'visio_type' => $enabled ? $visioType : null,
                    'visio_status' => $enabled ? 'programmee' : null,
                    'visio_room_id' => $enabled ? 'lms_seance_' . $seanceId . '_' . time() : null,
                    'visio_active' => false,
                    'updated_by' => $user->id,
                ]
            );

            if ($visio->wasRecentlyCreated) {
                $visio->created_by = $user->id;
                $visio->save();
            }

            // NOUVEAU: Envoyer notifications quand visio est activée (pattern Forum/Lessons)
            $notificationsSent = 0;
            if ($enabled) {
                try {
                    // Récupérer les infos de la séance depuis Klassci pour avoir classe_id et matiere
                    $seanceData = $this->getSeanceDataFromKlassci($seanceId, $klassciToken);

                    if ($seanceData) {
                        // Mettre à jour les IDs manquants dans la séance locale
                        $visio->update([
                            'klassci_matiere_id' => $seanceData['matiere_id'] ?? null,
                            'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                            'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                            'matiere_nom' => $seanceData['matiere_nom'] ?? null,
                            'enseignant_nom' => $seanceData['enseignant_nom'] ?? null,
                        ]);

                        // Synchroniser la classe pour avoir les étudiants
                        if (isset($seanceData['classe_id'])) {
                            $this->classeSyncService->syncClasseById(
                                $seanceData['classe_id'],
                                $klassciToken
                            );
                        }

                        // Envoyer les notifications immédiatement (comme Forum/Lessons)
                        $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                            'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                            'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                            'matiere_nom' => $seanceData['matiere_nom'] ?? 'Matière',
                            'enseignant_nom' => $seanceData['enseignant_nom'] ?? 'Enseignant',
                        ]);

                        Log::info('Notifications visio envoyées via toggleVisio', [
                            'seance_id' => $seanceId,
                            'notifications_sent' => $notificationsSent
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Erreur envoi notifications via toggleVisio', [
                        'seance_id' => $seanceId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
                'data' => [
                    'seance_id' => $seanceId,
                    'visio_enabled' => $visio->visio_enabled,
                    'visio_type' => $visio->visio_type,
                    'visio_room_id' => $visio->visio_room_id,
                    'visio_active' => $visio->visio_active,
                    'notifications_sent' => $notificationsSent,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur toggle visio séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation/désactivation de la visio',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/notifications/send-session-reminder
     */
    public function sendSessionReminder(\App\Http\Requests\SendSessionReminderRequest $request): JsonResponse
    {
        try {
            $seanceCoursId = $request->validated('seance_cours_id');
            $channels = $request->validated('channels');
            $minutesBefore = $request->validated('minutes_before') ?? 15;

            Log::info('Envoi rappel séance', [
                'seance_cours_id' => $seanceCoursId,
                'channels' => $channels,
                'minutes_before' => $minutesBefore
            ]);

            // TODO: Intégrer avec NotificationService existant

            return response()->json([
                'success' => true,
                'message' => 'Rappels envoyés avec succès',
                'data' => [
                    'seance_cours_id' => $seanceCoursId,
                    'channels' => $channels,
                    'sent_count' => 0,
                    'note' => 'TODO: Intégration NotificationService à compléter'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur envoi rappel séance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des rappels',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/my-teaching
     * Récupère les séances de l'enseignant connecté
     *
     * Retourne les séances où l'enseignant enseigne (basé sur son klassci_id)
     * avec infos visio et statuts
     */
    public function myTeachingSeances(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            Log::info('Récupération séances enseignant', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id
            ]);

            // Utiliser le teacher-dashboard qui contient les matières de l'enseignant
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/teacher-dashboard',
                'GET'
            );

            $matieres = collect($dashboard['data']['matieres'] ?? []);
            $seances = collect([]);

            // Pour chaque matière, récupérer ses séances
            foreach ($matieres as $matiere) {
                try {
                    $matiereDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiere['id']}",
                        'GET'
                    );

                    $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);

                    // Enrichir chaque séance avec les infos visio depuis notre BDD
                    $seancesEnrichies = $seancesProgrammees->map(function ($seance) use ($matiere, $user, $klassciToken) {
                        // Récupérer info visio depuis notre BDD
                        $visioData = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

                        // Créer une entrée locale si la séance n'existe pas encore dans notre BDD
                        // IMPORTANT: La visio n'est PAS activée automatiquement
                        // L'enseignant doit explicitement activer la visio pour qu'elle soit visible aux étudiants
                        if (!$visioData) {
                            try {
                                // Synchroniser la classe pour les notifications futures
                                if (isset($seance['classe']['id'])) {
                                    $this->classeSyncService->syncClasseById(
                                        $seance['classe']['id'],
                                        $klassciToken
                                    );
                                }

                                // Créer l'entrée locale SANS activer la visio
                                // L'enseignant devra cliquer sur "Activer la visio" pour la rendre visible
                                $visioData = \App\Models\Seance::create([
                                    'klassci_seance_id' => $seance['id'],
                                    'klassci_matiere_id' => $matiere['id'],
                                    'klassci_classe_id' => $seance['classe']['id'] ?? null,
                                    'klassci_enseignant_id' => $user->klassci_id,
                                    'enseignant_nom' => $user->name,
                                    'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                                    'visio_enabled' => false,  // Désactivé par défaut - l'enseignant doit activer
                                    'visio_type' => 'jitsi',
                                    'visio_status' => null,    // Pas de statut tant que non activé
                                    'visio_room_id' => null,   // Room créée lors de l'activation
                                    'visio_active' => false,
                                    'created_by' => $user->id,
                                ]);

                                Log::info('Séance Klassci détectée - En attente d\'activation par l\'enseignant', [
                                    'seance_id' => $seance['id'],
                                    'klassci_enseignant_id' => $user->klassci_id
                                ]);
                            } catch (\Exception $e) {
                                Log::error('Erreur création entrée séance locale', [
                                    'seance_id' => $seance['id'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }

                        // Récupérer effectif de la classe
                        $classeEffectif = 0;
                        if (isset($seance['classe']['id'])) {
                            try {
                                $classeDetails = $this->klassciService->requestWithUserToken(
                                    $klassciToken,
                                    "classes/{$seance['classe']['id']}",
                                    'GET'
                                );
                                $classeEffectif = $classeDetails['data']['classe']['places_occupees'] ?? 0;
                            } catch (\Exception $e) {
                                $classeEffectif = 0;
                            }
                        }

                        return [
                            'id' => $seance['id'],
                            // Garder compatibilité ancienne structure
                            'date_seance' => $seance['programmation']['date'] ?? null,
                            'heure_debut' => isset($seance['programmation']['heure_debut'])
                                ? substr($seance['programmation']['heure_debut'], 11, 5)
                                : null,
                            'heure_fin' => isset($seance['programmation']['heure_fin'])
                                ? substr($seance['programmation']['heure_fin'], 11, 5)
                                : null,
                            'salle' => $seance['programmation']['salle'] ?? null,
                            // Ajouter structure programmation pour cohérence avec autres endpoints
                            'programmation' => [
                                'date' => $seance['programmation']['date'] ?? null,
                                'heure_debut' => $seance['programmation']['heure_debut'] ?? null,
                                'heure_fin' => $seance['programmation']['heure_fin'] ?? null,
                                'salle' => $seance['programmation']['salle'] ?? null
                            ],
                            'matiere' => [
                                'id' => $matiere['id'],
                                'nom' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A',
                                'code' => $matiere['code'] ?? null
                            ],
                            'classe' => [
                                'id' => $seance['classe']['id'] ?? null,
                                'nom' => $seance['classe']['nom'] ?? 'N/A',
                                'effectif' => $classeEffectif
                            ],
                            'enseignant' => [
                                'id' => $user->klassci_id,
                                'nom' => $user->name
                            ],
                            // Infos visio (structure plate pour compatibilité frontend)
                            'visio_enabled' => $visioData ? $visioData->visio_enabled : false,
                            'visio_active' => $visioData ? $visioData->visio_active : false,
                            'visio_status' => $visioData ? $visioData->visio_status : null,
                            'visio_room_id' => $visioData ? $visioData->visio_room_id : null,
                            // Objet visio (structure imbriquée pour compatibilité)
                            'visio' => $visioData ? [
                                'enabled' => $visioData->visio_enabled,
                                'active' => $visioData->visio_active,
                                'status' => $visioData->visio_status,
                                'room_id' => $visioData->visio_room_id,
                                'started_at' => $visioData->visio_started_at,
                                'ended_at' => $visioData->visio_ended_at,
                                'participants_count' => $visioData->current_participants_count ?? 0
                            ] : null
                        ];
                    });

                    $seances = $seances->concat($seancesEnrichies);
                } catch (\Exception $e) {
                    Log::warning('Erreur récupération séances matière', [
                        'matiere_id' => $matiere['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Trier par date/heure
            $seances = $seances->sortBy('date_seance');

            return response()->json([
                'success' => true,
                'data' => $seances->values()->all()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération séances enseignant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/my-classes
     * Récupère les séances de l'étudiant connecté
     *
     * Retourne les séances de la classe de l'étudiant
     */
    public function myClassesSeances(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

            Log::info('Récupération séances étudiant', [
                'user_id' => $user->id,
                'klassci_id' => $user->klassci_id
            ]);

            // Récupérer le dashboard étudiant pour avoir sa classe
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET'
            );

            $classeId = $dashboard['data']['classe']['id'] ?? null;

            if (!$classeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune classe trouvée pour cet étudiant'
                ], 404);
            }

            // Utiliser les matières du dashboard au lieu de faire un nouvel appel API
            $coursFromDashboard = $dashboard['data']['cours'] ?? [];

            if (empty($coursFromDashboard)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune matière trouvée pour cet étudiant'
                ]);
            }

            $seances = collect([]);

            // Pour chaque matière, récupérer ses séances
            foreach ($coursFromDashboard as $matiere) {
                try {
                    // Gérer les différents formats de données (id ou matiere_id ou matiere.id)
                    $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;

                    if (!$matiereId) {
                        \Log::warning('[LMS] Matière sans ID valide', ['matiere' => $matiere]);
                        continue;
                    }

                    $matiereDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiereId}",
                        'GET'
                    );

                    $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);

                    // Filtrer uniquement les séances de la classe de l'étudiant
                    $seancesClasse = $seancesProgrammees->filter(function ($seance) use ($classeId) {
                        return ($seance['classe']['id'] ?? null) == $classeId;
                    });

                    // IMPORTANT: Pour les étudiants, on montre TOUTES les séances de leur classe
                    // L'emploi du temps doit être complet. On filtre seulement:
                    // 1. Les séances archivées (is_active = false)
                    // 2. Les séances masquées par l'étudiant
                    $seancesClasse = $seancesClasse->filter(function ($seance) use ($user) {
                        $localSeance = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

                        // Si la séance existe en local et est archivée, ne pas la montrer
                        if ($localSeance && !$localSeance->is_active) {
                            return false;
                        }

                        // Si la séance existe en local et est masquée par l'étudiant, ne pas la montrer
                        if ($localSeance && \App\Models\SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                            return false;
                        }

                        // Montrer la séance (même si pas de visio activée - c'est l'emploi du temps)
                        return true;
                    });

                    // Enrichir avec infos visio
                    $seancesEnrichies = $seancesClasse->map(function ($seance) use ($matiere, $matiereId) {
                        // Chercher la séance dans la BDD locale par klassci_seance_id
                        // IMPORTANT: Les étudiants ne voient que les séances actives (is_active = true)
                        $visioData = \App\Models\Seance::where('klassci_seance_id', $seance['id'])
                            ->where('is_active', true)
                            ->first();

                        // Si pas trouvé, chercher par nom de matière pour récupérer l'enseignant
                        $enseignantNom = 'Non assigné';
                        if ($visioData && $visioData->enseignant_nom) {
                            $enseignantNom = $visioData->enseignant_nom;
                        } else {
                            // Fallback: chercher une autre séance de la même matière pour récupérer l'enseignant
                            $matiereNom = $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? null;
                            if ($matiereNom) {
                                $autreSeance = \App\Models\Seance::where('matiere_nom', $matiereNom)
                                    ->whereNotNull('enseignant_nom')
                                    ->first();
                                if ($autreSeance) {
                                    $enseignantNom = $autreSeance->enseignant_nom;
                                    Log::info('Enseignant récupéré depuis autre séance même matière', [
                                        'seance_id' => $seance['id'],
                                        'matiere' => $matiereNom,
                                        'enseignant' => $enseignantNom
                                    ]);
                                }
                            }
                        }

                        // IMPORTANT: Utiliser la structure programmation comme les autres endpoints
                        return [
                            'id' => $seance['id'],
                            'programmation' => [
                                'date' => $seance['programmation']['date'] ?? null,
                                'heure_debut' => $seance['programmation']['heure_debut'] ?? null,
                                'heure_fin' => $seance['programmation']['heure_fin'] ?? null,
                                'salle' => $seance['programmation']['salle'] ?? null
                            ],
                            'salle' => $seance['programmation']['salle'] ?? null, // Aussi en racine pour compatibilité
                            'matiere' => [
                                'id' => $matiereId,
                                'nom' => $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? 'N/A',
                                'code' => $matiere['code'] ?? null
                            ],
                            'classe' => [
                                'id' => $seance['classe']['id'] ?? null,
                                'nom' => $seance['classe']['nom'] ?? 'N/A'
                            ],
                            'enseignant' => [
                                'nom' => $enseignantNom
                            ],
                            'visio' => $visioData ? [
                                // Une séance est considérée "avec visio" si:
                                // 1. visio_enabled = true OU
                                // 2. Elle a un statut actif (programmee, active)
                                'enabled' => $visioData->visio_enabled ||
                                            in_array($visioData->visio_status, ['programmee', 'active']),
                                'status' => $visioData->visio_status,
                                'room_id' => $visioData->visio_room_id,
                                'started_at' => $visioData->visio_started_at,
                                'participants_count' => $visioData->current_participants_count ?? 0
                            ] : null
                        ];
                    });

                    $seances = $seances->concat($seancesEnrichies);
                } catch (\Exception $e) {
                    Log::warning('Erreur récupération séances matière', [
                        'matiere_id' => $matiere['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Trier par date/heure
            $seances = $seances->sortBy('date_seance');

            return response()->json([
                'success' => true,
                'data' => $seances->values()->all()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération séances étudiant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/activate-visio
     * Activer la visioconférence pour une séance
     *
     * Workflow: Enseignant active → status = 'programmee'
     */
    public function activateVisio(int $seanceId, ActivateVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user->klassci_token;

            // Vérifier que la séance existe dans KLASSCI
            // Pour enseignants: utiliser teacher-dashboard
            // Pour coordinateurs: utiliser /matieres
            $seanceFound = null;
            $matiereInfo = null;

            if (in_array($user->role, ['enseignant', 'teacher'])) {
                $dashboard = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'me/teacher-dashboard',
                    'GET'
                );
                $matieres = collect($dashboard['data']['matieres'] ?? []);
            } else {
                $matieresResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'matieres',
                    'GET'
                );
                $matieres = collect($matieresResponse['data'] ?? []);
            }

            foreach ($matieres as $matiere) {
                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seances = collect($matiereDetails['data']['seances_programmees'] ?? []);
                $seanceFound = $seances->firstWhere('id', $seanceId);

                if ($seanceFound) {
                    $matiereInfo = $matiere;
                    break;
                }
            }

            if (!$seanceFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Créer ou mettre à jour l'entrée visio
            $visio = \App\Models\Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    'klassci_matiere_id' => $matiereInfo['id'] ?? null,
                    'klassci_classe_id' => $seanceFound['classe']['id'] ?? null,
                    'klassci_enseignant_id' => $user->klassci_id,
                    'enseignant_nom' => $user->name,
                    'matiere_nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? null,
                    'visio_enabled' => true,
                    'visio_type' => 'jitsi',
                    'visio_status' => 'programmee',
                    'visio_room_id' => 'lms_seance_' . $seanceId . '_' . time(),
                    'visio_active' => false,
                    'is_active' => true,  // S'assurer que la séance est active pour être visible aux étudiants
                    'updated_by' => $user->id,
                ]
            );

            Log::info('Visio activée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'room_id' => $visio->visio_room_id
            ]);

            // Synchroniser la classe et ses étudiants depuis Klassci
            // pour que les notifications puissent être envoyées
            $classe = null;
            try {
                if ($visio->klassci_classe_id) {
                    Log::info('Synchronisation classe pour notifications', [
                        'klassci_classe_id' => $visio->klassci_classe_id
                    ]);

                    $classe = $this->classeSyncService->syncClasseById(
                        $visio->klassci_classe_id,
                        $klassciToken
                    );

                    if ($classe) {
                        // Compter les étudiants synchronisés
                        $etudiantsCount = $classe->etudiantsActifs()->count();
                        Log::info('Classe synchronisée avec étudiants', [
                            'classe_id' => $classe->id,
                            'klassci_id' => $classe->klassci_id,
                            'libelle' => $classe->libelle,
                            'etudiants_actifs' => $etudiantsCount
                        ]);
                    } else {
                        Log::warning('Synchronisation classe échouée - classe null', [
                            'klassci_classe_id' => $visio->klassci_classe_id
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Erreur synchronisation classe', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // Envoyer les notifications aux étudiants
            $notificationsSent = 0;
            try {
                $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                    'klassci_classe_id' => $visio->klassci_classe_id,
                    'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                    'matiere_nom' => $visio->matiere_nom,
                    'enseignant_nom' => $visio->enseignant_nom,
                ]);

                Log::info('Notifications visio programmée envoyées', [
                    'seance_id' => $seanceId,
                    'notifications_sent' => $notificationsSent,
                    'classe_local_id' => $classe ? $classe->id : null
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur envoi notifications visio programmée', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence activée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'programmee',
                    'visio_room_id' => $visio->visio_room_id,
                    'notifications_sent' => $notificationsSent,
                    'classe_synced' => $classe ? true : false
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur activation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/deactivate-visio
     * Désactiver la visioconférence pour une séance
     *
     * Workflow: Enseignant désactive → visio_enabled = false
     */
    public function deactivateVisio(int $seanceId, DeactivateVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio || !$visio->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non activée pour cette séance'
                ], 404);
            }

            // Désactiver la visio
            $visio->update([
                'visio_enabled' => false,
                'visio_type' => null,
                'visio_status' => null,
                'visio_room_id' => null,
                'visio_active' => false,
                'visio_started_at' => null,
                'updated_by' => $user->id,
            ]);

            Log::info('Visio désactivée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence désactivée',
                'data' => [
                    'visio_enabled' => false
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur désactivation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la désactivation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/start-visio
     * Démarrer la visioconférence
     *
     * Workflow: Enseignant démarre → status = 'active'
     */
    public function startVisio(int $seanceId, StartVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non activée pour cette séance'
                ], 404);
            }

            if (!$visio->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'La visio doit être activée avant de démarrer'
                ], 400);
            }

            // Démarrer la visio
            $visio->update([
                'visio_status' => 'active',
                'visio_active' => true,
                'visio_started_at' => now(),
                'updated_by' => $user->id,
            ]);

            Log::info('Visio démarrée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'started_at' => $visio->visio_started_at
            ]);

            // Synchroniser la classe si pas déjà fait (sécurité)
            // Normalement déjà fait lors de activateVisio, mais on s'assure
            try {
                if ($visio->klassci_classe_id) {
                    $klassciToken = $user->klassci_token;
                    if ($klassciToken) {
                        $classe = $this->classeSyncService->syncClasseById(
                            $visio->klassci_classe_id,
                            $klassciToken
                        );
                    }
                }
            } catch (\Exception $e) {
                Log::error('Erreur synchronisation classe au démarrage', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // Envoyer les notifications aux étudiants et à l'enseignant
            try {
                $notificationsSent = $this->notificationService->notifyVisioStarting($seanceId, [
                    'klassci_classe_id' => $visio->klassci_classe_id,
                    'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                    'matiere_nom' => $visio->matiere_nom,
                    'enseignant_nom' => $visio->enseignant_nom,
                ]);

                Log::info('Notifications visio démarrée envoyées', [
                    'seance_id' => $seanceId,
                    'notifications_sent' => $notificationsSent
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur envoi notifications visio démarrée', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence démarrée',
                'data' => [
                    'visio_status' => 'active',
                    'visio_started_at' => $visio->visio_started_at,
                    'visio_room_id' => $visio->visio_room_id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur démarrage visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/end-visio
     * Terminer la visioconférence manuellement
     *
     * Workflow: Enseignant termine → status = 'terminee'
     */
    public function endVisio(int $seanceId, EndVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non trouvée'
                ], 404);
            }

            if ($visio->visio_status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'La visio n\'est pas active'
                ], 400);
            }

            // Terminer la visio
            $visio->update([
                'visio_status' => 'terminee',
                'visio_active' => false,
                'visio_ended_at' => now(),
                'updated_by' => $user->id,
            ]);

            Log::info('Visio terminée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'ended_at' => $visio->visio_ended_at,
                'participants_count' => $visio->current_participants_count
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence terminée',
                'data' => [
                    'visio_status' => 'terminee',
                    'visio_ended_at' => $visio->visio_ended_at,
                    'participants_count' => $visio->current_participants_count
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur fin visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la terminaison',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/join
     * Logger qu'un étudiant rejoint la visio
     */
    public function joinVisio(int $seanceId, JoinVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non trouvée'
                ], 404);
            }

            if ($visio->visio_status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'La visio n\'est pas active'
                ], 400);
            }

            // 🔑 RÈGLE PARTICIPANT FANTÔME
            // Les coordinateurs sont marqués comme "observateurs" (is_observer=true)
            // Ils ne sont PAS affichés dans la liste des participants visible
            // Mais leur présence est tracée pour l'audit
            $isObserver = ($user->role === 'coordinateur');

            // Enregistrer la participation pour tous les rôles
            // Étudiants, enseignants et coordinateurs peuvent rejoindre la visio
            $attendance = \App\Models\ESBTPAttendance::updateOrCreate(
                [
                    'seance_id' => $visio->id,
                    'user_id' => $user->id
                ],
                [
                    'klassci_etudiant_id' => $user->klassci_id,
                    'nom' => $user->name,
                    'prenom' => '',
                    'email' => $user->email,
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                    'status' => 'connected',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'is_validated' => true,
                    'is_observer' => $isObserver
                ]
            );

            $roleLabel = match($user->role) {
                'coordinateur' => 'Coordinateur (observateur)',
                'enseignant' => 'Enseignant',
                default => 'Étudiant'
            };

            Log::info("$roleLabel rejoint visio - participation enregistrée", [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'role' => $user->role,
                'is_observer' => $isObserver,
                'attendance_id' => $attendance->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accès à la visio autorisé',
                'data' => [
                    'visio_room_id' => $visio->visio_room_id,
                    'participants_count' => $visio->current_participants_count
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur join visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{seanceId}/participants
     * Récupérer la liste des participants réels à une visio
     */
    public function getVisioParticipants(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // 0. Déconnecter automatiquement les participants inactifs (timeout 3 minutes)
            // 🔑 SYSTÈME DE TIMEOUT : Détecte les participants qui n'envoient plus de heartbeat
            // et les marque automatiquement comme déconnectés
            $disconnectedCount = \App\Models\ESBTPAttendance::disconnectInactiveParticipants(3);
            if ($disconnectedCount > 0) {
                \Log::info('Participants déconnectés par timeout', [
                    'seance_id' => $seanceId,
                    'count' => $disconnectedCount
                ]);
            }

            // 1. Récupérer les participants qui ont RÉELLEMENT rejoint (table esbtp_attendance)
            // 🔑 RÈGLE PARTICIPANT FANTÔME : Exclure les observateurs (coordinateurs)
            // Les observateurs ne sont PAS affichés dans la liste visible des participants
            $actualParticipants = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('is_observer', false)  // 🔑 EXCLUSION DES OBSERVATEURS
                ->with('user:id,name,email,klassci_id')
                ->orderBy('joined_at', 'desc')
                ->get();

            // 2. Calculer la durée théorique de la séance (depuis programmation)
            $seanceDurationMinutes = 120; // Valeur par défaut
            $heureDebut = null;
            $heureFin = null;

            try {
                // Récupérer les détails de la séance pour avoir la programmation
                $seanceDetailsResponse = $this->seanceDetails($seanceId, $request);
                $seanceData = json_decode($seanceDetailsResponse->getContent(), true);

                if ($seanceData['success'] && isset($seanceData['data']['seance']['programmation'])) {
                    $prog = $seanceData['data']['seance']['programmation'];
                    $heureDebut = $prog['heure_debut'] ?? null;
                    $heureFin = $prog['heure_fin'] ?? null;

                    if ($heureDebut && $heureFin) {
                        $debut = new \DateTime($heureDebut);
                        $fin = new \DateTime($heureFin);
                        $seanceDurationMinutes = ($fin->getTimestamp() - $debut->getTimestamp()) / 60;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Impossible de calculer la durée de la séance', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // 3. Récupérer TOUS les étudiants de la classe (pour comparaison)
            $allClassStudents = [];

            try {
                $seanceDetailsResponse = $this->seanceDetails($seanceId, $request);
                $seanceData = json_decode($seanceDetailsResponse->getContent(), true);

                if ($seanceData['success'] && isset($seanceData['data']['participants']['students'])) {
                    $allClassStudents = $seanceData['data']['participants']['students'];
                }
            } catch (\Exception $e) {
                Log::warning('Impossible de récupérer la liste complète des étudiants via seanceDetails', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // Fallback : Si pas d'étudiants via KLASSCI, chercher dans la BDD locale
            if (empty($allClassStudents) && $visio->klassci_classe_id) {
                try {
                    Log::info('Fallback: Recherche étudiants via BDD locale', [
                        'seance_id' => $seanceId,
                        'classe_id' => $visio->klassci_classe_id
                    ]);

                    $students = \App\Models\UserClass::where('klassci_classe_id', $visio->klassci_classe_id)
                        ->join('users', 'user_classes.user_id', '=', 'users.id')
                        ->where('users.role', 'etudiant')
                        ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                        ->get();

                    $allClassStudents = $students->map(function($student) {
                        // Parser le nom complet (format: "NOM Prenom")
                        $nameParts = explode(' ', $student->name, 2);
                        return [
                            'id' => $student->id,
                            'nom' => $nameParts[0] ?? $student->name,
                            'prenom' => $nameParts[1] ?? '',
                            'email' => $student->email,
                            'klassci_id' => $student->klassci_id
                        ];
                    })->toArray();

                    Log::info('Fallback BDD: Étudiants trouvés', [
                        'count' => count($allClassStudents)
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erreur fallback BDD pour récupération étudiants', [
                        'seance_id' => $seanceId,
                        'classe_id' => $visio->klassci_classe_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 4. Créer un index des participants réels par user_id
            $actualParticipantsById = [];
            foreach ($actualParticipants as $attendance) {
                $actualParticipantsById[$attendance->user_id] = $attendance;
            }

            // 5. Construire la liste unifiée avec statut et pourcentage
            $unifiedList = [];

            foreach ($allClassStudents as $student) {
                $studentId = $student['id'];
                $isPresent = isset($actualParticipantsById[$studentId]);

                $studentData = [
                    'user_id' => $studentId,
                    'nom' => $student['nom'] ?? '',
                    'prenom' => $student['prenom'] ?? '',
                    'email' => $student['email'] ?? '',
                ];

                if ($isPresent) {
                    // Étudiant PRÉSENT - ajouter les détails de participation
                    $attendance = $actualParticipantsById[$studentId];

                    // Calculer la durée dynamiquement si l'étudiant est toujours connecté
                    if ($attendance->status === 'connected' && $attendance->joined_at) {
                        // Si toujours connecté, calculer la durée depuis joined_at jusqu'à maintenant
                        $durationMinutes = $attendance->joined_at->diffInMinutes(now());
                    } else {
                        // Si déconnecté, utiliser la durée enregistrée
                        $durationMinutes = $attendance->duration_minutes ?? 0;
                    }

                    $percentage = $seanceDurationMinutes > 0
                        ? round(($durationMinutes / $seanceDurationMinutes) * 100)
                        : 0;

                    // Déterminer le statut
                    $status = $this->determineAttendanceStatus(
                        $percentage,
                        $attendance->joined_at,
                        $attendance->left_at,
                        $heureDebut,
                        $heureFin,
                        $visio->visio_status  // Passer le statut de la visio
                    );

                    // Formatter la durée dynamiquement
                    $hours = floor($durationMinutes / 60);
                    $minutes = $durationMinutes % 60;
                    $durationFormatted = $hours > 0 ? "{$hours}h {$minutes}min" : "{$minutes}min";

                    // Déterminer left_at dynamiquement si toujours connecté
                    $leftAt = $attendance->left_at;
                    $leftAtFull = $attendance->left_at?->format('Y-m-d H:i:s');
                    $leftAtDisplay = $attendance->left_at?->format('H:i');

                    if ($attendance->status === 'connected') {
                        $leftAtDisplay = 'En cours';
                        $leftAtFull = null; // NULL = toujours connecté
                    }

                    $studentData = array_merge($studentData, [
                        'status' => $status['label'],
                        'status_icon' => $status['icon'],
                        'percentage' => $percentage,
                        'duration_minutes' => $durationMinutes,
                        'duration_formatted' => $durationFormatted,
                        'joined_at' => $attendance->joined_at?->format('H:i'),
                        'left_at' => $leftAtDisplay,
                        'joined_at_full' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                        'left_at_full' => $leftAtFull,
                        'is_late' => $status['is_late'],
                        'left_early' => $status['left_early'],
                        'is_present' => true,
                        'is_connected' => $attendance->status === 'connected' // Nouveau champ
                    ]);
                } else {
                    // Étudiant ABSENT
                    $studentData = array_merge($studentData, [
                        'status' => 'Absent',
                        'status_icon' => '❌',
                        'percentage' => 0,
                        'duration_minutes' => 0,
                        'duration_formatted' => '-',
                        'joined_at' => null,
                        'left_at' => null,
                        'joined_at_full' => null,
                        'left_at_full' => null,
                        'is_late' => false,
                        'left_early' => false,
                        'is_present' => false
                    ]);
                }

                $unifiedList[] = $studentData;
            }

            // 6. Trier la liste : Présents d'abord, puis par pourcentage décroissant, puis absents
            usort($unifiedList, function($a, $b) {
                // Présents avant absents
                if ($a['is_present'] !== $b['is_present']) {
                    return $b['is_present'] <=> $a['is_present'];
                }
                // Si tous deux présents, trier par pourcentage décroissant
                if ($a['is_present'] && $b['is_present']) {
                    return $b['percentage'] <=> $a['percentage'];
                }
                // Si tous deux absents, ordre alphabétique
                return strcmp($a['nom'], $b['nom']);
            });

            // 7. Calculer les statistiques globales
            // Compter seulement les participants ENCORE CONNECTÉS (pas ceux qui ont quitté)
            $presentCount = count(array_filter($unifiedList, fn($s) => ($s['is_connected'] ?? false)));
            $absentCount = count($unifiedList) - $presentCount;
            $lateCount = count(array_filter($unifiedList, fn($s) => $s['is_late'] ?? false));
            $leftEarlyCount = count(array_filter($unifiedList, fn($s) => $s['left_early'] ?? false));
            $completePresenceCount = count(array_filter($unifiedList, fn($s) => ($s['percentage'] ?? 0) === 100));

            // Calculer le pourcentage moyen de présence
            $totalPercentage = array_reduce($unifiedList, fn($sum, $s) => $sum + ($s['percentage'] ?? 0), 0);
            $averagePercentage = count($unifiedList) > 0 ? round($totalPercentage / count($unifiedList)) : 0;

            // Calculer la durée moyenne
            $totalDuration = array_reduce($unifiedList, fn($sum, $s) => $sum + ($s['duration_minutes'] ?? 0), 0);
            $averageDuration = $presentCount > 0 ? round($totalDuration / $presentCount) : 0;

            $stats = [
                'total_students' => count($unifiedList),
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'presence_rate' => count($unifiedList) > 0 ? round(($presentCount / count($unifiedList)) * 100) : 0,
                'complete_presence_count' => $completePresenceCount,
                'late_count' => $lateCount,
                'left_early_count' => $leftEarlyCount,
                'average_percentage' => $averagePercentage,
                'average_duration_minutes' => $averageDuration,
                'seance_duration_minutes' => $seanceDurationMinutes,
                'visio_status' => $visio->visio_status  // Ajouter pour conditionner affichage %
            ];

            // Récupérer l'info enseignant depuis la table seances
            $teacherInfo = [
                'nom' => $visio->enseignant_nom ?? null,
                'prenom' => $visio->enseignant_prenom ?? null,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'students' => $unifiedList,
                    'statistics' => $stats,
                    'teacher' => $teacherInfo,
                    'seance' => [
                        'id' => $seanceId,
                        'matiere' => $visio->matiere_nom,
                        'classe_id' => $visio->klassci_classe_id,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération participants', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Détermine le statut de présence d'un étudiant
     */
    private function determineAttendanceStatus($percentage, $joinedAt, $leftAt, $heureDebut, $heureFin, $visioStatus = null): array
    {
        $RETARD_SEUIL_MINUTES = 5;

        $isLate = false;
        $leftEarly = false;

        // Vérifier le retard (si heureDebut disponible)
        if ($heureDebut && $joinedAt) {
            try {
                $debut = new \DateTime($heureDebut);
                $joined = new \DateTime($joinedAt);
                $diffMinutes = ($joined->getTimestamp() - $debut->getTimestamp()) / 60;

                if ($diffMinutes > $RETARD_SEUIL_MINUTES) {
                    $isLate = true;
                }
            } catch (\Exception $e) {
                // Ignorer les erreurs de parsing
            }
        }

        // Vérifier le départ anticipé (si heureFin disponible)
        if ($heureFin && $leftAt) {
            try {
                $fin = new \DateTime($heureFin);
                $left = new \DateTime($leftAt);
                $diffMinutes = ($fin->getTimestamp() - $left->getTimestamp()) / 60;

                if ($diffMinutes > $RETARD_SEUIL_MINUTES) {
                    $leftEarly = true;
                }
            } catch (\Exception $e) {
                // Ignorer les erreurs de parsing
            }
        }

        // Déterminer le label et l'icône
        if ($percentage === 100) {
            return [
                'label' => 'Présent (complet)',
                'icon' => '✅',
                'is_late' => false,
                'left_early' => false
            ];
        } elseif ($percentage >= 90) {
            return [
                'label' => $visioStatus === 'terminee' ? "Présent ({$percentage}%)" : "Présent",
                'icon' => '✅',
                'is_late' => $isLate,
                'left_early' => $leftEarly
            ];
        } elseif ($percentage >= 50) {
            if ($visioStatus === 'terminee') {
                $label = "Présent ({$percentage}%)";
                if ($isLate && $leftEarly) {
                    $label = "Partiel ({$percentage}%)";
                } elseif ($isLate) {
                    $label = "Retard ({$percentage}%)";
                } elseif ($leftEarly) {
                    $label = "Départ anticipé ({$percentage}%)";
                }
            } else {
                // Pendant la visio active : pas de pourcentage
                $label = "Présent";
                if ($isLate && $leftEarly) {
                    $label = "Partiel";
                } elseif ($isLate) {
                    $label = "Retard";
                } elseif ($leftEarly) {
                    $label = "Départ anticipé";
                }
            }

            return [
                'label' => $label,
                'icon' => '⚠️',
                'is_late' => $isLate,
                'left_early' => $leftEarly
            ];
        } else {
            return [
                'label' => $visioStatus === 'terminee' ? "Présent ({$percentage}%)" : "Présent",
                'icon' => '⚠️',
                'is_late' => $isLate,
                'left_early' => $leftEarly
            ];
        }
    }

    /**
     * POST /api/seances/{seanceId}/leave
     * Enregistrer la sortie d'un participant de la visio
     */
    public function leaveVisio(int $seanceId, LeaveVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non trouvée'
                ], 404);
            }

            // Trouver la participation active de l'utilisateur
            $attendance = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune participation active trouvée'
                ], 404);
            }

            // Marquer comme déconnecté
            $attendance->markAsDisconnected();

            Log::info('Participant quitté visio', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'duration_minutes' => $attendance->duration_minutes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion enregistrée',
                'data' => [
                    'left_at' => $attendance->left_at->format('Y-m-d H:i:s'),
                    'duration_minutes' => $attendance->duration_minutes,
                    'duration_formatted' => $attendance->formatted_duration
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur leave visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement de la sortie',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/seances/{seanceId}/heartbeat
     * Mettre à jour le heartbeat d'un participant (ping d'activité)
     */
    public function heartbeatVisio(int $seanceId, HeartbeatVisioRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non trouvée'
                ], 404);
            }

            // Trouver la participation active de l'utilisateur
            $attendance = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune participation active trouvée'
                ], 404);
            }

            // Mettre à jour le heartbeat
            $attendance->updateHeartbeat();

            return response()->json([
                'success' => true,
                'data' => [
                    'last_seen_at' => $attendance->last_seen_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur heartbeat visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du heartbeat',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/hide
     * Masquer une séance pour l'utilisateur connecté
     *
     * Permet à un étudiant de masquer une séance de sa vue personnelle
     * sans affecter les autres utilisateurs
     */
    public function hideSeance(int $seanceId, \App\Http\Requests\HideSeanceRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Vérifier que la séance existe
            $seance = \App\Models\Seance::where('id', $seanceId)
                ->orWhere('klassci_seance_id', $seanceId)
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Masquer la séance
            \App\Models\SeanceUserHidden::hide($seance->id, $user->id);

            Log::info('Séance masquée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id,
                'matiere' => $seance->matiere_nom
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Séance masquée avec succès',
                'data' => [
                    'seance_id' => $seance->id,
                    'hidden_at' => now()->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur masquage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du masquage de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/unhide
     * Réafficher une séance précédemment masquée
     */
    public function unhideSeance(int $seanceId, \App\Http\Requests\UnhideSeanceRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Vérifier que la séance existe
            $seance = \App\Models\Seance::where('id', $seanceId)
                ->orWhere('klassci_seance_id', $seanceId)
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Réafficher la séance
            $unHidden = \App\Models\SeanceUserHidden::unhide($seance->id, $user->id);

            if (!$unHidden) {
                return response()->json([
                    'success' => false,
                    'message' => 'La séance n\'était pas masquée'
                ], 404);
            }

            Log::info('Séance réaffichée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Séance réaffichée avec succès',
                'data' => [
                    'seance_id' => $seance->id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur réaffichage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du réaffichage de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/admin/matieres
     * Liste toutes les matières avec leurs combinaisons complètes (pour admin/coordinateur)
     *
     * Retourne:
     * - Liste des matières enrichies avec combinaisons valides (filière + niveau)
     * - Statistiques globales
     */
    public function adminMatieresList(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $klassciToken = $user ? $user->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            // Vérifier que l'utilisateur est admin ou coordinateur
            if (!in_array($user->role, ['admin', 'coordinateur', 'superAdmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Réservé aux administrateurs et coordinateurs.'
                ], 403);
            }

            Log::info('Récupération liste matières admin', [
                'user_id' => $user->id,
                'role' => $user->role
            ]);

            // 1. Récupérer la liste des matières depuis l'endpoint /matieres
            $matieresResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'matieres',
                'GET'
            );

            $matieres = $matieresResponse['data'] ?? [];

            if (count($matieres) === 0) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'matieres' => [],
                        'statistiques' => [
                            'total' => 0,
                            'total_heures' => 0,
                            'total_seances' => 0
                        ]
                    ],
                    'message' => 'Aucune matière trouvée'
                ]);
            }

            Log::info('Matières trouvées', ['count' => count($matieres)]);

            // 2. Enrichir chaque matière avec ses combinaisons complètes
            $matieresEnrichies = [];

            foreach ($matieres as $matiere) {
                $matiereId = $matiere['id'];

                try {
                    // Récupérer les détails complets de la matière
                    $detailsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiereId}",
                        'GET'
                    );

                    $details = $detailsResponse['data'] ?? [];
                    $combinaisons = $details['combinaisons'] ?? [];

                    // Enrichir la matière avec les combinaisons complètes
                    $matieresEnrichies[] = [
                        'id' => $matiere['id'],
                        'nom' => $matiere['nom'] ?? 'N/A',
                        'code' => $matiere['code'] ?? 'N/A',
                        'description' => $matiere['description'] ?? null,
                        'coefficient' => $matiere['coefficient'] ?? null,
                        'couleur' => $matiere['couleur'] ?? '#6366f1',
                        'heures_total' => $matiere['heures_total'] ?? 0,
                        'nb_seances_programmees' => $matiere['nb_seances_programmees'] ?? 0,
                        'nb_lecons' => $matiere['nb_lecons'] ?? 0,
                        'nb_evaluations' => $matiere['nb_evaluations'] ?? 0,
                        'combinaisons' => $combinaisons // Combinaisons complètes avec filière/niveau
                    ];

                    Log::info('Matière enrichie', [
                        'id' => $matiereId,
                        'nom' => $matiere['nom'],
                        'combinaisons_count' => count($combinaisons)
                    ]);

                } catch (\Exception $e) {
                    Log::warning('Erreur enrichissement matière', [
                        'matiere_id' => $matiereId,
                        'error' => $e->getMessage()
                    ]);

                    // En cas d'erreur, garder la matière avec combinaisons vides
                    $matieresEnrichies[] = [
                        'id' => $matiere['id'],
                        'nom' => $matiere['nom'] ?? 'N/A',
                        'code' => $matiere['code'] ?? 'N/A',
                        'description' => $matiere['description'] ?? null,
                        'coefficient' => $matiere['coefficient'] ?? null,
                        'couleur' => $matiere['couleur'] ?? '#6366f1',
                        'heures_total' => $matiere['heures_total'] ?? 0,
                        'nb_seances_programmees' => $matiere['nb_seances_programmees'] ?? 0,
                        'nb_lecons' => $matiere['nb_lecons'] ?? 0,
                        'nb_evaluations' => $matiere['nb_evaluations'] ?? 0,
                        'combinaisons' => []
                    ];
                }
            }

            // 3. Calculer les statistiques globales
            $totalHeures = array_sum(array_column($matieresEnrichies, 'heures_total'));
            $totalSeances = array_sum(array_column($matieresEnrichies, 'nb_seances_programmees'));

            $stats = [
                'total' => count($matieresEnrichies),
                'total_heures' => $totalHeures,
                'total_seances' => $totalSeances
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'matieres' => $matieresEnrichies,
                    'statistiques' => $stats
                ],
                'message' => count($matieresEnrichies) . ' matière(s) récupérée(s)'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur liste matières admin', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des matières',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/enseignants
     * Retourne la liste des enseignants avec leurs classes, matières et statistiques
     *
     * Paramètres disponibles:
     * - with_details (boolean): Activer le format enrichi
     * - filiere_id (int): Filtrer par filière
     * - niveau_id (int): Filtrer par niveau
     * - classe_id (int): Filtrer par classe
     * - matiere_id (int): Filtrer par matière
     *
     * Format simple: Liste basique des enseignants
     * Format enrichi: Avec classes, matières, séances et statistiques
     */
    public function getEnseignants(Request $request): JsonResponse
    {
        try {
            $startTime = microtime(true);
            $withDetails = $request->boolean('with_details', false);

            Log::info('[LMS Enseignants API] Début récupération', [
                'with_details' => $withDetails,
                'filters' => $request->only(['filiere_id', 'niveau_id', 'classe_id', 'matiere_id'])
            ]);

            // Vérifier que les tables nécessaires existent
            // Si pas de table esbtp_teachers, utiliser fallback avec table users
            $useFallback = !Schema::hasTable('esbtp_teachers');

            if ($useFallback) {
                Log::info('[LMS Enseignants API] Mode fallback - utilisation table users uniquement');

                // Version simplifiée avec table users seulement
                $enseignants = DB::table('users')
                    ->whereIn('role', ['enseignant', 'teacher'])
                    ->select([
                        'id',
                        'id as teacher_id',
                        'name as nom',
                        'email',
                        'role',
                        DB::raw('NULL as matricule'),
                        DB::raw('NULL as specialization'),
                        DB::raw('"permanent" as status')
                    ])
                    ->get();

                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Log::info('[LMS Enseignants API] Terminé (mode fallback)', [
                    'duration_ms' => $duration,
                    'count' => $enseignants->count()
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $enseignants,
                    'mode' => 'fallback'
                ]);
            }

            // Récupérer l'année universitaire courante (optionnel)
            $anneeUniversitaireCourante = null;
            if (Schema::hasTable('esbtp_annee_universitaires')) {
                $anneeUniversitaireCourante = DB::table('esbtp_annee_universitaires')
                    ->where('is_current', true)
                    ->first();
            }

            Log::info('[LMS Enseignants API] Année universitaire', [
                'found' => $anneeUniversitaireCourante !== null,
                'id' => $anneeUniversitaireCourante->id ?? null
            ]);

            // Query de base: récupérer les enseignants actifs
            $enseignantsQuery = DB::table('esbtp_teachers as t')
                ->join('users as u', 't.user_id', '=', 'u.id')
                ->whereNull('t.deleted_at')
                ->where('t.is_active', true)
                ->select([
                    'u.id as id',
                    't.id as teacher_id',
                    't.nom',
                    't.email',
                    'u.role',
                    't.matricule',
                    't.specialization',
                    't.status'
                ]);

            // Appliquer les filtres si format enrichi
            if ($withDetails) {
                if ($request->has('classe_id')) {
                    $classeId = $request->input('classe_id');
                    $enseignantsQuery->whereExists(function($query) use ($classeId, $anneeUniversitaireCourante) {
                        $query->select(DB::raw(1))
                            ->from('esbtp_seance_cours as sc')
                            ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                            ->whereColumn('sc.enseignant_id', 't.id')
                            ->where('sc.classe_id', $classeId);

                        if ($anneeUniversitaireCourante) {
                            $query->where('et.annee_universitaire_id', $anneeUniversitaireCourante->id);
                        }
                    });
                }

                if ($request->has('matiere_id')) {
                    $matiereId = $request->input('matiere_id');
                    $enseignantsQuery->whereExists(function($query) use ($matiereId, $anneeUniversitaireCourante) {
                        $query->select(DB::raw(1))
                            ->from('esbtp_seance_cours as sc')
                            ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                            ->whereColumn('sc.enseignant_id', 't.id')
                            ->where('sc.matiere_id', $matiereId);

                        if ($anneeUniversitaireCourante) {
                            $query->where('et.annee_universitaire_id', $anneeUniversitaireCourante->id);
                        }
                    });
                }

                if ($request->has('filiere_id') || $request->has('niveau_id')) {
                    $filiereId = $request->input('filiere_id');
                    $niveauId = $request->input('niveau_id');

                    $enseignantsQuery->whereExists(function($query) use ($filiereId, $niveauId, $anneeUniversitaireCourante) {
                        $query->select(DB::raw(1))
                            ->from('esbtp_seance_cours as sc')
                            ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                            ->join('esbtp_classes as c', 'sc.classe_id', '=', 'c.id')
                            ->join('esbtp_combinaisons as comb', 'c.combinaison_id', '=', 'comb.id')
                            ->whereColumn('sc.enseignant_id', 't.id');

                        if ($anneeUniversitaireCourante) {
                            $query->where('et.annee_universitaire_id', $anneeUniversitaireCourante->id);
                        }

                        if ($filiereId) {
                            $query->where('comb.filiere_id', $filiereId);
                        }
                        if ($niveauId) {
                            $query->where('comb.niveau_id', $niveauId);
                        }
                    });
                }
            }

            $enseignants = $enseignantsQuery->get();

            Log::info('[LMS Enseignants API] Enseignants récupérés', [
                'count' => $enseignants->count()
            ]);

            // Format simple: retourner directement
            if (!$withDetails) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                Log::info('[LMS Enseignants API] Terminé (format simple)', [
                    'duration_ms' => $duration
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $enseignants
                ]);
            }

            // Format enrichi: ajouter classes, matières, séances et statistiques
            $enseignantsEnrichis = $enseignants->map(function($enseignant) use ($anneeUniversitaireCourante) {
                $data = (array) $enseignant;

                // 1. Récupérer les classes
                $classesQuery = DB::table('esbtp_seance_cours as sc')
                    ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                    ->join('esbtp_classes as c', 'sc.classe_id', '=', 'c.id')
                    ->join('esbtp_combinaisons as comb', 'c.combinaison_id', '=', 'comb.id')
                    ->join('esbtp_filieres as f', 'comb.filiere_id', '=', 'f.id')
                    ->join('esbtp_niveaux as n', 'comb.niveau_id', '=', 'n.id')
                    ->where('sc.enseignant_id', $enseignant->teacher_id);

                if ($anneeUniversitaireCourante) {
                    $classesQuery->where('et.annee_universitaire_id', $anneeUniversitaireCourante->id);
                }

                $classes = $classesQuery
                    ->select([
                        'c.id',
                        'c.nom',
                        'f.id as filiere_id',
                        'f.nom as filiere_nom',
                        'n.id as niveau_id',
                        'n.nom as niveau_nom'
                    ])
                    ->distinct()
                    ->get()
                    ->map(function($classe) {
                        return [
                            'id' => $classe->id,
                            'nom' => $classe->libelle,
                            'filiere' => [
                                'id' => $classe->filiere_id,
                                'nom' => $classe->filiere_nom
                            ],
                            'niveau' => [
                                'id' => $classe->niveau_id,
                                'nom' => $classe->niveau_nom
                            ]
                        ];
                    });

                // 2. Récupérer les matières avec détails
                $matieres = $this->getMatieresEnrichiesForEnseignant(
                    $enseignant->teacher_id,
                    $anneeUniversitaireCourante ? $anneeUniversitaireCourante->id : null
                );

                // 3. Calculer statistiques globales
                $statistiques = [
                    'total_classes' => $classes->count(),
                    'total_matieres' => $matieres->count(),
                    'total_heures_prevues' => $matieres->sum('heures_prevues'),
                    'total_heures_effectuees' => $matieres->sum('heures_effectuees'),
                    'total_heures_restantes' => $matieres->sum('heures_restantes'),
                    'taux_realisation_global' => $matieres->avg('taux_realisation') ?? 0,
                    'nb_seances_total' => $matieres->sum('nb_seances_total'),
                    'nb_seances_effectuees' => $matieres->sum('nb_seances_effectuees')
                ];

                $statistiques['taux_realisation_global'] = round($statistiques['taux_realisation_global'], 2);

                $data['classes'] = $classes->values()->toArray();
                $data['matieres'] = $matieres->values()->toArray();
                $data['statistiques'] = $statistiques;

                return $data;
            });

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('[LMS Enseignants API] Terminé (format enrichi)', [
                'duration_ms' => $duration,
                'count' => $enseignantsEnrichis->count()
            ]);

            return response()->json([
                'success' => true,
                'data' => $enseignantsEnrichis
            ]);

        } catch (\Exception $e) {
            Log::error('[LMS Enseignants API] Erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des enseignants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/enseignants-klassci
     * Version NOUVELLE qui utilise KLASSCI externe + cache intelligent
     * Remplace la logique de lecture des tables locales esbtp_*
     */
    public function getEnseignantsFromKlassci(Request $request): JsonResponse
    {
        try {
            $startTime = microtime(true);
            $withDetails = $request->boolean('with_details', false);

            Log::info('[LMS Enseignants KLASSCI] Récupération depuis API externe', [
                'with_details' => $withDetails
            ]);

            // Appeler KLASSCI externe via le service
            $response = $this->klassciService->getEnseignantsEnrichis($withDetails);

            if (!isset($response['success']) || !$response['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $response['message'] ?? 'Erreur KLASSCI',
                    'data' => []
                ], 500);
            }

            $enseignants = $response['data'] ?? [];

            // Stocker en cache si format enrichi
            if ($withDetails && !empty($enseignants)) {
                foreach ($enseignants as $enseignant) {
                    if (isset($enseignant['id'])) {
                        try {
                            LmsEnseignantCache::store($enseignant['id'], $enseignant, 10);
                        } catch (\Exception $cacheErr) {
                            // Log but continue
                        }
                    }
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'data' => $enseignants,
                'meta' => array_merge($response['meta'] ?? [], [
                    'source' => 'klassci_externe',
                    'lms_cache_enabled' => true,
                    'lms_performance' => [
                        'total_time_ms' => $duration
                    ]
                ])
            ]);

        } catch (\Exception $e) {
            Log::error('[LMS Enseignants KLASSCI] Erreur', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Récupère les matières enrichies pour un enseignant
     */
    private function getMatieresEnrichiesForEnseignant(int $teacherId, ?int $anneeUniversitaireId): \Illuminate\Support\Collection
    {
        // Récupérer les matières de l'enseignant via les séances
        $matieresQuery = DB::table('esbtp_seance_cours as sc')
            ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
            ->join('esbtp_matieres as m', 'sc.matiere_id', '=', 'm.id')
            ->where('sc.enseignant_id', $teacherId);

        if ($anneeUniversitaireId) {
            $matieresQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
        }

        $matieres = $matieresQuery
            ->select([
                'm.id',
                'm.nom',
                'm.code'
            ])
            ->distinct()
            ->get();

        return $matieres->map(function($matiere) use ($teacherId, $anneeUniversitaireId) {
            // Récupérer heures prévues (priorité: pivot > planning > séances)
            $heuresPrevues = 0;

            // 1. Essayer pivot table
            if ($anneeUniversitaireId) {
                $pivot = DB::table('esbtp_enseignant_matiere')
                    ->where('enseignant_id', $teacherId)
                    ->where('matiere_id', $matiere->id)
                    ->where('annee_universitaire_id', $anneeUniversitaireId)
                    ->first();

                if ($pivot && $pivot->heures_prevues > 0) {
                    $heuresPrevues = $pivot->heures_prevues;
                }
            }

            if ($heuresPrevues == 0 && $anneeUniversitaireId) {
                // 2. Essayer planning général
                $planning = DB::table('esbtp_planifications_academiques as pa')
                    ->join('esbtp_planification_teachers as pt', 'pa.id', '=', 'pt.planification_id')
                    ->where('pt.enseignant_id', $teacherId)
                    ->where('pa.matiere_id', $matiere->id)
                    ->where('pa.annee_universitaire_id', $anneeUniversitaireId)
                    ->first();

                if ($planning && $planning->volume_horaire_total > 0) {
                    $heuresPrevues = $planning->volume_horaire_total;
                }
            }

            if ($heuresPrevues == 0) {
                // 3. Fallback: calculer depuis séances
                $seancesQuery = DB::table('esbtp_seance_cours as sc')
                    ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                    ->where('sc.enseignant_id', $teacherId)
                    ->where('sc.matiere_id', $matiere->id);

                if ($anneeUniversitaireId) {
                    $seancesQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
                }

                $heuresPrevues = $seancesQuery->sum(DB::raw('TIMESTAMPDIFF(HOUR, sc.heure_debut, sc.heure_fin)')) ?? 0;
            }

            // Récupérer heures effectuées depuis attendances
            $attendancesQuery = DB::table('esbtp_teacher_attendances as ta')
                ->join('esbtp_seance_cours as sc', 'ta.seance_id', '=', 'sc.id')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->where('ta.teacher_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id)
                ->whereIn('ta.status', ['present', 'late'])
                ->where('ta.type', 'start');

            if ($anneeUniversitaireId) {
                $attendancesQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $nbAttendances = $attendancesQuery->count();

            // Calculer durée moyenne séance
            $dureeMoyenneQuery = DB::table('esbtp_seance_cours as sc')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->where('sc.enseignant_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id);

            if ($anneeUniversitaireId) {
                $dureeMoyenneQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $dureeMoyenne = $dureeMoyenneQuery->avg(DB::raw('TIMESTAMPDIFF(HOUR, sc.heure_debut, sc.heure_fin)')) ?? 2;

            $heuresEffectuees = $nbAttendances * $dureeMoyenne;
            $heuresRestantes = max(0, $heuresPrevues - $heuresEffectuees);
            $tauxRealisation = $heuresPrevues > 0 ? ($heuresEffectuees / $heuresPrevues) * 100 : 0;

            // Nombre de séances
            $nbSeancesTotalQuery = DB::table('esbtp_seance_cours as sc')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->where('sc.enseignant_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id);

            if ($anneeUniversitaireId) {
                $nbSeancesTotalQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $nbSeancesTotal = $nbSeancesTotalQuery->count();

            $nbSeancesEffectueesQuery = DB::table('esbtp_teacher_attendances as ta')
                ->join('esbtp_seance_cours as sc', 'ta.seance_id', '=', 'sc.id')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->where('ta.teacher_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id)
                ->whereIn('ta.status', ['present', 'late'])
                ->where('ta.type', 'start')
                ->distinct('sc.id');

            if ($anneeUniversitaireId) {
                $nbSeancesEffectueesQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $nbSeancesEffectuees = $nbSeancesEffectueesQuery->count('sc.id');

            // Récupérer les classes pour cette matière
            $classesQuery = DB::table('esbtp_seance_cours as sc')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->join('esbtp_classes as c', 'sc.classe_id', '=', 'c.id')
                ->where('sc.enseignant_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id);

            if ($anneeUniversitaireId) {
                $classesQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $classes = $classesQuery
                ->select(['c.id', 'c.nom'])
                ->distinct()
                ->get()
                ->map(function($c) {
                    return ['id' => $c->id, 'nom' => $c->nom];
                });

            // Récupérer les séances
            $seancesQuery = DB::table('esbtp_seance_cours as sc')
                ->join('esbtp_emploi_temps as et', 'sc.emploi_temps_id', '=', 'et.id')
                ->join('esbtp_classes as c', 'sc.classe_id', '=', 'c.id')
                ->leftJoin('esbtp_teacher_attendances as ta', function($join) use ($teacherId) {
                    $join->on('ta.seance_id', '=', 'sc.id')
                         ->where('ta.teacher_id', '=', $teacherId)
                         ->where('ta.type', '=', 'start');
                })
                ->where('sc.enseignant_id', $teacherId)
                ->where('sc.matiere_id', $matiere->id);

            if ($anneeUniversitaireId) {
                $seancesQuery->where('et.annee_universitaire_id', $anneeUniversitaireId);
            }

            $seances = $seancesQuery
                ->select([
                    'sc.id',
                    'sc.date_seance',
                    'sc.heure_debut',
                    'sc.heure_fin',
                    'c.nom as classe',
                    'sc.salle',
                    'ta.status as attendance_status'
                ])
                ->orderBy('sc.date_seance', 'desc')
                ->orderBy('sc.heure_debut', 'desc')
                ->limit(10)
                ->get()
                ->map(function($s) {
                    $now = Carbon::now();
                    $seanceDateTime = Carbon::parse($s->date_seance . ' ' . $s->heure_fin);

                    $status = 'a_venir';
                    if ($seanceDateTime->isPast()) {
                        $status = in_array($s->attendance_status, ['present', 'late']) ? 'effectuee' : 'annulee';
                    }

                    return [
                        'id' => $s->id,
                        'date_seance' => $s->date_seance,
                        'heure_debut' => $s->heure_debut,
                        'heure_fin' => $s->heure_fin,
                        'classe' => $s->classe,
                        'salle' => $s->salle,
                        'status' => $status
                    ];
                });

            return [
                'id' => $matiere->id,
                'nom' => $matiere->nom,
                'code' => $matiere->code,
                'heures_prevues' => round($heuresPrevues, 2),
                'heures_effectuees' => round($heuresEffectuees, 2),
                'heures_restantes' => round($heuresRestantes, 2),
                'taux_realisation' => round($tauxRealisation, 2),
                'nb_seances_total' => $nbSeancesTotal,
                'nb_seances_effectuees' => $nbSeancesEffectuees,
                'classes' => $classes->toArray(),
                'seances' => $seances->toArray()
            ];
        });
    }

    /**
     * GET /api/lms/teacher/my-matieres
     * Récupérer les matières de l'enseignant connecté avec statistiques enrichies
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
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

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

            $matieres = $klassciData['data']['matieres'] ?? [];
            $seances = $klassciData['data']['seances'] ?? [];
            $evaluations = $klassciData['data']['evaluations'] ?? [];

            // 2. Enrichir chaque matière avec données LMS locales
            $matieresEnrichies = array_map(function ($matiere) use ($user, $seances, $evaluations) {
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
                $nombreEvaluations = collect($evaluations)->filter(function ($evaluation) use ($matiereId) {
                    $evalMatiereId = $evaluation['matiere']['id'] ??
                                     $evaluation['matiere']['matiere_id'] ??
                                     $evaluation['matiere_id'] ?? null;
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
            Log::error('Erreur myMatieres', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des matières: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les données d'une séance depuis Klassci
     * Cherche dans toutes les matières pour trouver la séance
     */
    private function getSeanceDataFromKlassci(int $seanceId, string $klassciToken): ?array
    {
        try {
            // Récupérer toutes les matières de l'enseignant
            $matieres = $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');

            foreach ($matieres['data'] ?? [] as $matiere) {
                // Récupérer les détails de chaque matière
                $details = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seances = $details['data']['seances_programmees'] ?? [];

                // Chercher la séance
                foreach ($seances as $seance) {
                    if ($seance['id'] == $seanceId) {
                        // Séance trouvée!
                        return [
                            'matiere_id' => $matiere['id'],
                            'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                            'classe_id' => $seance['classe']['id'] ?? null,
                            'classe_nom' => $seance['classe']['nom'] ?? null,
                            'enseignant_id' => $details['data']['enseignant']['id'] ?? null,
                            'enseignant_nom' => $details['data']['enseignant']['nom_complet'] ?? null,
                        ];
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Erreur récupération données séance Klassci', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * GET /api/attendance/history
     * Récupérer l'historique des présences (accessible même si séance archivée)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAttendanceHistory(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Paramètres de filtrage
            $seanceId = $request->input('seance_id');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $matiereId = $request->input('matiere_id');
            $classeId = $request->input('classe_id');

            // Construire la requête de base
            $query = \App\Models\ESBTPAttendance::with(['user:id,name,email', 'seance'])
                ->orderBy('joined_at', 'desc');

            // Filtre selon le rôle
            if ($user->role === 'enseignant') {
                // L'enseignant ne voit que les présences de ses propres séances
                $query->whereHas('seance', function($q) use ($user) {
                    $q->where('enseignant_id', $user->id);
                });
            } else if ($user->role === 'etudiant') {
                // L'étudiant ne voit que ses propres présences
                $query->where('user_id', $user->id);
            }
            // Les coordinateurs/superAdmin voient tout

            // Filtres optionnels
            if ($seanceId) {
                $seanceLocal = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
                if ($seanceLocal) {
                    $query->where('seance_id', $seanceLocal->id);
                }
            }

            if ($dateFrom) {
                $query->where('joined_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->where('joined_at', '<=', $dateTo . ' 23:59:59');
            }

            // Pagination
            $perPage = $request->input('per_page', 50);
            $attendances = $query->paginate($perPage);

            // Enrichir les données avec les infos KLASSCI si disponibles
            $enrichedData = $attendances->getCollection()->map(function($attendance) use ($request) {
                $data = [
                    'id' => $attendance->id,
                    'user' => [
                        'id' => $attendance->user?->id ?? 0,
                        'name' => $attendance->user?->name ?? 'Utilisateur supprimé',
                        'email' => $attendance->user?->email ?? '',
                    ],
                    'seance' => [
                        'id' => $attendance->seance?->id ?? 0,
                        'klassci_seance_id' => $attendance->seance?->klassci_seance_id ?? 'N/A',
                        'date' => $attendance->seance?->date_seance ?? null,
                        'matiere' => null,
                        'classe' => null
                    ],
                    'joined_at' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                    'left_at' => $attendance->left_at?->format('Y-m-d H:i:s'),
                    'last_seen_at' => $attendance->last_seen_at?->format('Y-m-d H:i:s'),
                    'status' => $attendance->status,
                    'duration_minutes' => null,
                ];

                // Calculer la durée
                if ($attendance->joined_at && $attendance->left_at) {
                    $data['duration_minutes'] = $attendance->joined_at->diffInMinutes($attendance->left_at);
                }

                // Essayer de récupérer les infos KLASSCI de la séance
                if ($attendance->seance && $attendance->seance->klassci_seance_id) {
                    try {
                        $seanceDetails = $this->seanceDetails($attendance->seance->klassci_seance_id, $request);
                    $seanceData = json_decode($seanceDetails->getContent(), true);

                    if ($seanceData['success'] && isset($seanceData['data']['seance'])) {
                        $data['seance']['matiere'] = $seanceData['data']['seance']['matiere'] ?? null;
                        $data['seance']['classe'] = $seanceData['data']['seance']['classe'] ?? null;
                        $data['seance']['programmation'] = $seanceData['data']['seance']['programmation'] ?? null;
                    }
                    } catch (\Exception $e) {
                        // Ignorer les erreurs de récupération KLASSCI (séance peut être archivée)
                        Log::debug('Impossible de récupérer détails KLASSCI pour historique', [
                            'seance_id' => $attendance->seance->klassci_seance_id,
                            'error' => 'Une erreur est survenue.'
                        ]);
                    }
                }

                return $data;
            });

            return response()->json([
                'success' => true,
                'data' => $enrichedData,
                'pagination' => [
                    'current_page' => $attendances->currentPage(),
                    'per_page' => $attendances->perPage(),
                    'total' => $attendances->total(),
                    'last_page' => $attendances->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération historique présences', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/history
     * Liste des séances ayant eu une session visio (avec statistiques de présence)
     */
    public function getSeancesHistory(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Base query : seulement les séances qui ont eu une visio démarrée
            $query = \App\Models\Seance::whereNotNull('visio_started_at')
                ->orderBy('visio_started_at', 'desc');

            // Filtre par rôle
            if ($user->role === 'enseignant') {
                $query->where('klassci_enseignant_id', $user->klassci_id);
            }
            // coordinateur / superAdmin : pas de filtre, voit tout

            // Filtres optionnels
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $search = $request->input('search');

            if ($dateFrom) {
                $query->where('visio_started_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('visio_started_at', '<=', $dateTo . ' 23:59:59');
            }
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('matiere_nom', 'like', "%{$search}%")
                      ->orWhere('classe_nom', 'like', "%{$search}%")
                      ->orWhere('enseignant_nom', 'like', "%{$search}%")
                      ->orWhere('titre', 'like', "%{$search}%");
                });
            }

            // Pagination
            $perPage = $request->input('per_page', 50);
            $seances = $query->paginate($perPage);

            // Enrichir chaque séance avec les statistiques de présence
            $enrichedData = $seances->getCollection()->map(function ($seance) {
                // Récupérer les attendances non-observateurs
                $attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
                    ->where(function ($q) {
                        $q->where('is_observer', false)
                          ->orWhereNull('is_observer');
                    })
                    ->get();

                $participantsCount = $attendances->count();

                // Durées valides (> 0)
                $durations = $attendances->pluck('duration_minutes')
                    ->filter(fn($d) => $d !== null && $d > 0);

                $avgDuration = $durations->count() > 0
                    ? round($durations->avg())
                    : 0;

                // Présence valide = duration > 3 minutes
                $validPresences = $durations->filter(fn($d) => $d > 3)->count();

                $presenceRate = $participantsCount > 0
                    ? round(($validPresences / $participantsCount) * 100)
                    : 0;

                // Durée de la séance
                $seanceDurationMinutes = null;
                if ($seance->visio_started_at && $seance->visio_ended_at) {
                    $diffMinutes = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
                    if ($diffMinutes <= 1440) {
                        $seanceDurationMinutes = $diffMinutes;
                    }
                }

                return [
                    'id' => $seance->id,
                    'klassci_seance_id' => $seance->klassci_seance_id,
                    'titre' => $seance->titre ?? 'Séance #' . $seance->klassci_seance_id,
                    'matiere' => [
                        'nom' => $seance->matiere_nom ?? 'Matière inconnue',
                    ],
                    'classe' => [
                        'nom' => $seance->classe_nom ?? '-',
                    ],
                    'date' => $seance->date_seance
                        ? $seance->date_seance->format('Y-m-d')
                        : ($seance->visio_started_at
                            ? $seance->visio_started_at->format('Y-m-d')
                            : null),
                    'visio_started_at' => $seance->visio_started_at?->toIso8601String(),
                    'visio_ended_at' => $seance->visio_ended_at?->toIso8601String(),
                    'duree_seance_minutes' => $seanceDurationMinutes,
                    'participants_count' => $participantsCount,
                    'duree_moyenne_minutes' => $avgDuration,
                    'taux_presence' => $presenceRate,
                    'enseignant_nom' => $seance->enseignant_nom,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $enrichedData->values(),
                'pagination' => [
                    'current_page' => $seances->currentPage(),
                    'per_page' => $seances->perPage(),
                    'total' => $seances->total(),
                    'last_page' => $seances->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération historique séances', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique des séances',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{seanceId}/attendances
     * Présences détaillées pour une séance donnée
     */
    public function getSeanceAttendances(Request $request, int $seanceId): JsonResponse
    {
        try {
            $user = $request->user();

            // Chercher par ID local d'abord, puis par klassci_seance_id
            $seance = \App\Models\Seance::find($seanceId);
            if (!$seance) {
                $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée',
                ], 404);
            }

            // Contrôle d'accès par rôle
            if ($user->role === 'enseignant') {
                if ($seance->klassci_enseignant_id != $user->klassci_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès refusé. Cette séance ne vous appartient pas.',
                    ], 403);
                }
            }

            // Durée de la séance
            $seanceDurationMinutes = null;
            if ($seance->visio_started_at && $seance->visio_ended_at) {
                $diffMinutes = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
                if ($diffMinutes <= 1440) {
                    $seanceDurationMinutes = $diffMinutes;
                }
            }

            $isFinished = $seance->visio_ended_at !== null;

            // Infos séance
            $seanceData = [
                'id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'enseignant_nom' => $seance->enseignant_nom,
                'coordinateur_nom' => null,
                'visio_started_at' => $seance->visio_started_at?->toIso8601String(),
                'visio_ended_at' => $seance->visio_ended_at?->toIso8601String(),
                'duration_minutes' => $seanceDurationMinutes,
                'is_finished' => $isFinished,
                'matiere_nom' => $seance->matiere_nom ?? 'Matière inconnue',
                'date' => $seance->date_seance
                    ? $seance->date_seance->format('Y-m-d')
                    : ($seance->visio_started_at
                        ? $seance->visio_started_at->format('Y-m-d')
                        : null),
            ];

            // Récupérer toutes les attendances
            $allAttendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
                ->orderBy('joined_at', 'asc')
                ->get();

            // Statistiques (non-observateurs uniquement)
            $nonObserverAttendances = $allAttendances->filter(function ($att) {
                return !$att->is_observer;
            });

            $totalParticipants = $nonObserverAttendances->count();

            $durations = $nonObserverAttendances->pluck('duration_minutes')
                ->filter(fn($d) => $d !== null && $d > 0);

            $averageDuration = $durations->count() > 0
                ? round($durations->avg())
                : 0;

            $validPresences = $durations->filter(fn($d) => $d > 3)->count();

            $presenceRate = $totalParticipants > 0
                ? round(($validPresences / $totalParticipants) * 100)
                : 0;

            $statistics = [
                'total_participants' => $totalParticipants,
                'average_duration' => $averageDuration,
                'presence_rate' => $presenceRate,
            ];

            // Formater les attendances individuelles
            $formattedAttendances = $allAttendances->map(function ($att) use ($seanceDurationMinutes) {
                $durationMinutes = $att->duration_minutes ?? 0;

                // Pourcentage de participation par rapport à la durée de la séance
                $participationPercentage = 0;
                if ($seanceDurationMinutes && $seanceDurationMinutes > 0) {
                    $participationPercentage = min(100, round(($durationMinutes / $seanceDurationMinutes) * 100));
                }

                // Niveau de statut pour l'affichage frontend
                if ($durationMinutes <= 3) {
                    $statusLevel = 'danger';
                    $presenceStatus = 'Absent';
                } elseif ($participationPercentage >= 75) {
                    $statusLevel = 'success';
                    $presenceStatus = 'Présent';
                } elseif ($participationPercentage >= 25) {
                    $statusLevel = 'warning';
                    $presenceStatus = 'Partiel';
                } else {
                    $statusLevel = 'danger';
                    $presenceStatus = 'Insuffisant';
                }

                // Observateurs
                if ($att->is_observer) {
                    $statusLevel = 'info';
                    $presenceStatus = 'Observateur';
                }

                $fullName = trim(($att->prenom ?? '') . ' ' . ($att->nom ?? ''));
                if (empty($fullName)) {
                    $fullName = $att->email ?? 'Participant inconnu';
                }

                return [
                    'id' => $att->id,
                    'nom' => $fullName,
                    'email' => $att->email,
                    'joined_at' => $att->joined_at,
                    'left_at' => $att->left_at,
                    'duration_minutes' => $durationMinutes,
                    'status' => $att->status,
                    'status_level' => $statusLevel,
                    'presence_status' => $presenceStatus,
                    'participation_percentage' => $participationPercentage,
                    'is_observer' => (bool) $att->is_observer,
                ];
            });

            return response()->json([
                'success' => true,
                'seance' => $seanceData,
                'statistics' => $statistics,
                'attendances' => $formattedAttendances->values(),
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération attendances séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des présences',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * DELETE /api/lms/seances/{seanceId}
     * Soft-delete d'une séance
     */
    public function deleteSeance(\App\Http\Requests\DeleteSeanceRequest $request, int $seanceId): JsonResponse
    {
        try {
            $user = $request->user();

            // Chercher par ID local d'abord, puis par klassci_seance_id
            $seance = \App\Models\Seance::find($seanceId);
            if (!$seance) {
                $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            // Empêcher la suppression si la visio est en cours
            if ($seance->visio_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une séance dont la visioconférence est en cours.',
                ], 422);
            }

            Log::info('Suppression séance', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'deleted_by' => $user->id,
                'user_role' => $user->role,
            ]);

            // Soft delete
            $seance->delete();

            return response()->json([
                'success' => true,
                'message' => 'Séance supprimée avec succès',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la séance',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
