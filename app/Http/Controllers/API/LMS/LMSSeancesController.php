<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteSeanceRequest;
use App\Http\Requests\HideSeanceRequest;
use App\Http\Requests\ToggleVisioSeanceRequest;
use App\Http\Requests\UnhideSeanceRequest;
use App\Http\Requests\ValidateParticipantRequest;
use App\Services\KlassciProxyService;
use App\Services\SeanceQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * LMS Seances — séances générales (hors visio actions).
 *
 * Extracted from `LMSDataController` (PR F of split spec). 11 methods.
 * `seanceDetails()` is delegated to `SeanceQueryService` (PR E).
 */
final class LMSSeancesController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly SeanceQueryService $seanceQuery,
    ) {}

    /**
     * GET /api/lms/seances/{id}/details
     * Détails complets d'une séance avec infos visio.
     *
     * Logic delegated to SeanceQueryService (PR E) to eliminate the legacy
     * inline ~500-line block and JSON encode/decode anti-pattern.
     */
    public function seanceDetails(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $data = $this->seanceQuery->getSeanceDetailsArray($seanceId, $user);

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (RuntimeException $e) {
            // Thrown by SeanceQueryService when user has no klassci_token
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 401);
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

    public function upcomingSeances(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
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
            $user = $this->authenticatedUser($request);
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
            $currentUser = $this->authenticatedUser($request);
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
    public function toggleVisioSeance(int $seanceId, \App\Http\Requests\ToggleVisioSeanceRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
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
     *
     * @deprecated Migrated to {@see \App\Http\Controllers\API\LMS\LMSNotificationsPreferencesController::sendSessionReminder}.
     *             This copy is unreachable (routes/api.php now points to the new controller) and will be
     *             removed in Phase C cleanup (PR J of the LMS split spec).
     *             DO NOT modify this version — fix the new controller instead.
     */
    public function myTeachingSeances(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
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
            $user = $this->authenticatedUser($request);
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
    public function hideSeance(int $seanceId, \App\Http\Requests\HideSeanceRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

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
            $user = $this->authenticatedUser($request);

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
     *
     * @deprecated Migrated to {@see \App\Http\Controllers\API\LMS\LMSMatieresController::adminMatieresList}.
     *             This copy is unreachable (routes/api.php now points to the new controller) and will be
     *             removed in Phase C cleanup (PR J of the LMS split spec).
     *             DO NOT modify this version — fix the new controller instead.
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
    public function getSeancesHistory(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

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
    public function deleteSeance(\App\Http\Requests\DeleteSeanceRequest $request, int $seanceId): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

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
