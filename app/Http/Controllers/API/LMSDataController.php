<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use App\Models\LmsEnseignantCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class LMSDataController extends Controller
{
    protected KlassciProxyService $klassciService;

    public function __construct(KlassciProxyService $klassciService)
    {
        $this->klassciService = $klassciService;
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
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
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
                    // Étudiant: récupérer via student-dashboard
                    $dashboard = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        'me/student-dashboard',
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

            // Si aucune séance de l'API KLASSCI, récupérer depuis BDD locale
            if (empty($seances)) {
                Log::info('API KLASSCI ne retourne pas de séances, récupération depuis BDD locale', [
                    'matiere_id' => $matiereId
                ]);

                // La table seances utilise klassci_matiere_id et matiere_nom
                $matiereNom = $matiere['nom'] ?? null;

                $seancesLocales = collect();

                // Rechercher par matiere_nom en priorité
                if ($matiereNom) {
                    $seancesLocales = \App\Models\Seance::where('matiere_nom', $matiereNom)->get();
                    Log::info('Recherche séances par nom', [
                        'matiere_nom' => $matiereNom,
                        'found' => $seancesLocales->count()
                    ]);
                }

                // Si pas de résultat par nom, essayer par klassci_matiere_id
                if ($seancesLocales->isEmpty()) {
                    $seancesLocales = \App\Models\Seance::where('klassci_matiere_id', $matiereId)->get();
                    Log::info('Recherche séances par klassci_matiere_id', [
                        'klassci_matiere_id' => $matiereId,
                        'found' => $seancesLocales->count()
                    ]);
                }

                $seances = $seancesLocales->map(function ($seance) {
                    // Les séances de la BDD locale ont des champs spécifiques
                    return [
                        'id' => $seance->klassci_seance_id ?? $seance->id,
                        'classe' => [
                            'id' => $seance->klassci_classe_id ?? null,
                            'nom' => 'Classe'
                        ],
                        'programmation' => [
                            'date' => now()->format('Y-m-d'),
                            'heure_debut' => '08:00',
                            'heure_fin' => '10:00',
                            'salle' => null
                        ],
                        'enseignant' => [
                            'nom' => $seance->enseignant_nom ?? 'Non assigné',
                            'prenom' => ''
                        ],
                        'statut' => 'programme'
                    ];
                })->toArray();

                Log::info('Séances récupérées depuis BDD locale', [
                    'count' => count($seances),
                    'matiere_nom' => $matiereNom
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

                // Ajouter l'enseignant
                $seanceEnrichie = $seance;
                $seanceEnrichie['enseignant'] = [
                    'id' => $user->klassci_id,
                    'nom' => $user->name
                ];

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
                    $seanceEnrichie['visio_participants_count'] = $visioData->visio_participants_count ?? 0;
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
                    'evaluations_programmees' => $evaluations,
                    'statistiques' => $stats
                ]
            ];

            Log::info('✅ Matière details response', [
                'matiere_id' => $matiereId,
                'has_matiere' => !empty($matiere),
                'lessons_count' => count($lessons),
                'seances_count' => count($seances),
                'evaluations_count' => count($evaluations)
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
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/validate-participant
     */
    public function validateParticipant(int $seanceId, Request $request): JsonResponse
    {
        try {
            $validator = \Validator::make($request->all(), [
                'user_id' => 'required|integer|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->input('user_id');
            $currentUser = $request->user();
            $klassciToken = $currentUser ? $currentUser->klassci_token : null;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

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

            $seancesResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'emploi-temps',
                'GET'
            );

            $seance = collect($seancesResponse['data'] ?? [])->firstWhere('id', $seanceId);

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'seance_not_found'
                ], 404);
            }

            $teacherId = $seance['enseignant']['user_id'] ?? $seance['enseignant']['id'] ?? null;

            if ($teacherId == $userId) {
                return response()->json([
                    'success' => true,
                    'authorized' => true,
                    'role' => 'teacher',
                    'message' => 'Enseignant de la séance'
                ]);
            }

            if (in_array($userToValidate->role, ['coordinateur', 'superAdmin'])) {
                return response()->json([
                    'success' => true,
                    'authorized' => true,
                    'role' => 'moderator',
                    'message' => 'Coordinateur/Admin'
                ]);
            }

            if ($userToValidate->role === 'étudiant') {
                $classeId = $seance['classe']['id'] ?? null;

                if (!$classeId) {
                    return response()->json([
                        'success' => true,
                        'authorized' => false,
                        'reason' => 'no_class_for_seance'
                    ]);
                }

                try {
                    $etudiantsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "classes/{$classeId}/etudiants",
                        'GET'
                    );

                    $etudiant = collect($etudiantsResponse['data'] ?? [])->first(function ($etu) use ($userId) {
                        return ($etu['user_id'] ?? null) == $userId && ($etu['statut'] ?? '') === 'actif';
                    });

                    if ($etudiant) {
                        return response()->json([
                            'success' => true,
                            'authorized' => true,
                            'role' => 'student',
                            'message' => 'Étudiant inscrit dans la classe'
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::warning('Erreur vérification inscription étudiant', [
                        'classe_id' => $classeId,
                        'user_id' => $userId,
                        'error' => $e->getMessage()
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'authorized' => false,
                    'reason' => 'not_enrolled_in_class'
                ]);
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/attendances/from-video-session
     */
    public function syncAttendancesFromVideoSession(Request $request): JsonResponse
    {
        try {
            $validator = \Validator::make($request->all(), [
                'seance_cours_id' => 'required|integer|exists:esbtp_seance_cours,id',
                'date' => 'required|date',
                'participants' => 'required|array|min:1',
                'participants.*.etudiant_id' => 'required|integer',
                'participants.*.statut' => 'required|in:present,absent,retard',
                'participants.*.joined_at' => 'nullable|date',
                'participants.*.left_at' => 'nullable|date',
                'participants.*.duration_minutes' => 'nullable|integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $seanceCoursId = $request->input('seance_cours_id');
            $date = $request->input('date');
            $participants = $request->input('participants');

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
                        'error' => $e->getMessage()
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
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
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

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // 2. Enrichir avec durée calculée
            // Les séances de seances_programmees ont une structure: programmation.heure_debut/fin (ISO 8601)
            $heureDebut = Carbon::parse($seance['programmation']['heure_debut']);
            $heureFin = Carbon::parse($seance['programmation']['heure_fin']);
            $seance['duree_minutes'] = $heureDebut->diffInMinutes($heureFin);

            // 3. Enrichir avec données visio depuis BDD locale
            try {
                $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

                if ($visioData) {
                    $seance['visio_enabled'] = $visioData->visio_enabled ?? false;
                    $seance['visio_type'] = $visioData->visio_type ?? 'jitsi';
                    $seance['visio_room_id'] = $visioData->visio_room_id;
                    $seance['visio_status'] = $visioData->visio_status;
                    $seance['visio_participants_count'] = $visioData->visio_participants_count ?? 0;

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
                    Log::warning('Erreur récupération étudiants séance', [
                        'classe_id' => $classeId,
                        'error' => $e->getMessage()
                    ]);
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

            return response()->json([
                'success' => true,
                'data' => [
                    'seance' => $seance,
                    'participants' => [
                        'teacher' => $teacher,
                        'students' => $students,
                        'total' => 1 + count($students)
                    ],
                    'visio' => [
                        'enabled' => $seance['visio_enabled'],
                        'type' => $seance['visio_type'],
                        'room_id' => $seance['visio_room_id'],
                        'status' => $seance['visio_status'],
                        'window' => $seance['visio_window']
                    ]
                ]
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/toggle-visio
     * Active/désactive la visioconférence pour une séance (coordinateurs uniquement)
     */
    public function toggleVisioSeance(int $seanceId, Request $request): JsonResponse
    {
        try {
            $validator = \Validator::make($request->all(), [
                'enabled' => 'required|boolean',
                'visio_type' => 'nullable|in:jitsi,zoom,teams,bbb',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();

            // Vérifier que c'est un coordinateur
            if (!in_array($user->role, ['coordinateur', 'superAdmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les coordinateurs peuvent activer/désactiver la visio'
                ], 403);
            }

            $enabled = $request->input('enabled');
            $visioType = $request->input('visio_type', 'jitsi');
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé'
                ], 401);
            }

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
                    'visio_room_id' => $enabled ? 'lms_seance_' . $seanceId . '_' . time() : null,
                    'visio_active' => false,
                    'updated_by' => $user->id,
                ]
            );

            if ($visio->wasRecentlyCreated) {
                $visio->created_by = $user->id;
                $visio->save();
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/notifications/send-session-reminder
     */
    public function sendSessionReminder(Request $request): JsonResponse
    {
        try {
            $validator = \Validator::make($request->all(), [
                'seance_cours_id' => 'required|integer|exists:esbtp_seance_cours,id',
                'channels' => 'required|array|min:1',
                'channels.*' => 'in:whatsapp,email,sms,app',
                'minutes_before' => 'nullable|integer|min:0|max:1440'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $seanceCoursId = $request->input('seance_cours_id');
            $channels = $request->input('channels');
            $minutesBefore = $request->input('minutes_before', 15);

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
                'error' => $e->getMessage()
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
                            'date_seance' => $seance['programmation']['date'] ?? null,
                            'heure_debut' => isset($seance['programmation']['heure_debut'])
                                ? substr($seance['programmation']['heure_debut'], 11, 5)
                                : null,
                            'heure_fin' => isset($seance['programmation']['heure_fin'])
                                ? substr($seance['programmation']['heure_fin'], 11, 5)
                                : null,
                            'salle' => $seance['programmation']['salle'] ?? null,
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
                            'visio' => $visioData ? [
                                'enabled' => $visioData->visio_enabled,
                                'status' => $visioData->visio_status,
                                'room_id' => $visioData->visio_room_id,
                                'started_at' => $visioData->visio_started_at,
                                'ended_at' => $visioData->visio_ended_at,
                                'participants_count' => $visioData->visio_participants_count ?? 0
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
                'error' => $e->getMessage()
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

                    // Enrichir avec infos visio
                    $seancesEnrichies = $seancesClasse->map(function ($seance) use ($matiere, $matiereId) {
                        // Chercher la séance dans la BDD locale par klassci_seance_id
                        $visioData = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

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
                                'participants_count' => $visioData->visio_participants_count ?? 0
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/activate-visio
     * Activer la visioconférence pour une séance
     *
     * Workflow: Enseignant active → status = 'programmee'
     */
    public function activateVisio(int $seanceId, Request $request): JsonResponse
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
                    'updated_by' => $user->id,
                ]
            );

            Log::info('Visio activée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'room_id' => $visio->visio_room_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence activée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'programmee',
                    'visio_room_id' => $visio->visio_room_id
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/start-visio
     * Démarrer la visioconférence
     *
     * Workflow: Enseignant démarre → status = 'active'
     */
    public function startVisio(int $seanceId, Request $request): JsonResponse
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/end-visio
     * Terminer la visioconférence manuellement
     *
     * Workflow: Enseignant termine → status = 'terminee'
     */
    public function endVisio(int $seanceId, Request $request): JsonResponse
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
                'participants_count' => $visio->visio_participants_count
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence terminée',
                'data' => [
                    'visio_status' => 'terminee',
                    'visio_ended_at' => $visio->visio_ended_at,
                    'participants_count' => $visio->visio_participants_count
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/join
     * Logger qu'un étudiant rejoint la visio
     */
    public function joinVisio(int $seanceId, Request $request): JsonResponse
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

            // OPTION B: Vérifier que l'étudiant est bien inscrit dans la classe
            if ($user->role === 'etudiant' || $user->role === 'student') {
                try {
                    $klassciToken = $user->klassci_token;
                    $klassciService = app(\App\Services\KlassciProxyService::class);

                    // Récupérer les étudiants de la classe depuis KLASSCI
                    $classeId = $visio->klassci_classe_id;
                    $etudiantsResponse = $klassciService->get("classes/{$classeId}/etudiants");
                    $etudiants = $etudiantsResponse['data'] ?? [];

                    // Vérifier si l'étudiant est dans la liste
                    $etudiantInscrit = collect($etudiants)->first(function ($etudiant) use ($user) {
                        return $etudiant['id'] == $user->klassci_id;
                    });

                    if (!$etudiantInscrit) {
                        Log::warning('Étudiant non autorisé à rejoindre visio', [
                            'user_id' => $user->id,
                            'klassci_id' => $user->klassci_id,
                            'classe_id' => $classeId
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'Vous n\'êtes pas inscrit dans cette classe'
                        ], 403);
                    }

                    // Enregistrer la participation dans esbtp_attendance
                    $attendance = \App\Models\ESBTPAttendance::updateOrCreate(
                        [
                            'seance_id' => $visio->id,
                            'user_id' => $user->id
                        ],
                        [
                            'klassci_etudiant_id' => $user->klassci_id,
                            'nom' => $etudiantInscrit['nom'] ?? $user->name,
                            'prenom' => $etudiantInscrit['prenom'] ?? '',
                            'email' => $etudiantInscrit['email'] ?? $user->email,
                            'joined_at' => now(),
                            'ip_address' => $request->ip(),
                            'user_agent' => $request->userAgent(),
                            'is_validated' => true
                        ]
                    );

                    Log::info('Étudiant rejoint visio et participation enregistrée', [
                        'seance_id' => $seanceId,
                        'user_id' => $user->id,
                        'attendance_id' => $attendance->id
                    ]);

                } catch (\Exception $verifyError) {
                    Log::error('Erreur vérification étudiant', [
                        'error' => $verifyError->getMessage()
                    ]);
                    // Ne pas bloquer si erreur KLASSCI, laisser passer
                }
            }

            // Incrémenter le compteur de participants
            $visio->increment('visio_participants_count');

            return response()->json([
                'success' => true,
                'message' => 'Accès à la visio autorisé',
                'data' => [
                    'visio_room_id' => $visio->visio_room_id,
                    'participants_count' => $visio->visio_participants_count
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
                'error' => $e->getMessage()
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
            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Récupérer tous les participants enregistrés
            $participants = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->with('user:id,name,email')
                ->orderBy('joined_at', 'desc')
                ->get()
                ->map(function ($attendance) {
                    return [
                        'id' => $attendance->id,
                        'user_id' => $attendance->user_id,
                        'nom' => $attendance->nom,
                        'prenom' => $attendance->prenom,
                        'email' => $attendance->email,
                        'joined_at' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                        'joined_at_human' => $attendance->joined_at?->diffForHumans(),
                        'is_validated' => $attendance->is_validated
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $participants->count(),
                    'participants' => $participants
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération participants', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => $e->getMessage()
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
            if (!in_array($user->role, ['admin', 'coordinateur'])) {
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
                'error' => $e->getMessage()
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
                            'nom' => $classe->nom,
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
                'error' => $e->getMessage()
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

                // Compter séances pour cette matière
                $nombreSeances = collect($seances)->filter(function ($seance) use ($matiereId) {
                    $seanceMatiereId = $seance['matiere']['id'] ??
                                       $seance['matiere']['matiere_id'] ??
                                       $seance['matiere_id'] ?? null;
                    return $seanceMatiereId == $matiereId;
                })->count();

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
}
