<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use App\Services\ClasseSyncService;
use App\Services\TenantManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controller d'authentification
 *
 * Gère l'authentification via l'API KLASSCI et génère des tokens Sanctum locaux
 */
class AuthController extends Controller
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private ClasseSyncService $classeSyncService
    ) {}

    /**
     * POST /api/auth/login
     * Connexion utilisateur (local ou proxy vers KLASSCI)
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Validation des données - accepter username OU email
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',  // Accepter username au lieu de email
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Essayer d'abord l'authentification locale (si DB accessible)
            try {
                $user = User::withoutGlobalScope('institution')
                            ->where('email', $request->username)
                            ->orWhere('name', $request->username)
                            ->first();

                if ($user && Hash::check($request->password, $user->password)) {
                    // Authentification locale réussie
                    $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

                    $isSupradmin = $user->role === 'supradmin';

                    return response()->json([
                        'success' => true,
                        'message' => 'Connexion réussie (local)',
                        'data' => [
                            'user' => [
                                'id' => $user->id,
                                'klassci_id' => $user->klassci_id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'role' => $user->role,
                            ],
                            'token' => $token,
                            'token_type' => 'Bearer',
                        ],
                        'meta' => [
                            'klassci_synced' => false,
                            'is_supradmin' => $isSupradmin,
                            'institution' => $isSupradmin ? null : app(TenantManager::class)->slug(),
                            'institution_name' => $isSupradmin ? null : app(TenantManager::class)->get()?->name,
                        ],
                    ]);
                }
            } catch (\Exception $dbError) {
                // Si erreur DB, on passe directement à KLASSCI
                Log::warning('DB locale non accessible, passage à KLASSCI', [
                    'error' => $dbError->getMessage()
                ]);
            }

            // Si pas d'utilisateur local, essayer via KLASSCI
            try {
                // KLASSCI attend uniquement 'username' et 'password'
                $klassciResponse = $this->klassciService->post('auth/login', [
                    'username' => $request->username,  // Utiliser le username fourni
                    'password' => $request->password,
                ]);

                Log::info('KLASSCI Login', [
                    'success' => $klassciResponse['success'] ?? false,
                    'user_id' => $klassciResponse['data']['user']['id'] ?? null,
                ]);

                // Vérifier la réponse KLASSCI
                if (!isset($klassciResponse['success']) || !$klassciResponse['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Identifiants incorrects',
                    ], 401);
                }

                // Vérifier que les données utilisateur existent
                if (!isset($klassciResponse['data']['user']) || !isset($klassciResponse['data']['token'])) {
                    Log::error('KLASSCI Response manquante', ['success' => $klassciResponse['success'] ?? false]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Réponse KLASSCI invalide',
                    ], 500);
                }

                $klassciUser = $klassciResponse['data']['user'];
                $klassciToken = $klassciResponse['data']['token'];

                // Synchroniser l'utilisateur localement et créer un token Sanctum
                try {
                    $localUser = $this->syncUserFromKlassci($klassciUser, $klassciToken);

                    // Créer un token Sanctum pour l'utilisateur local
                    $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

                    // Synchroniser les classes de l'utilisateur en arrière-plan (non-bloquant)
                    // Cela permet aux notifications de fonctionner correctement
                    $syncStats = null;
                    try {
                        $syncStats = $this->classeSyncService->syncUserClasses($klassciToken, $localUser->role);
                        Log::info('Classes synchronisées au login', [
                            'user_id' => $localUser->id,
                            'stats' => $syncStats
                        ]);
                    } catch (\Exception $syncError) {
                        Log::warning('Erreur synchronisation classes au login', [
                            'user_id' => $localUser->id,
                            'error' => $syncError->getMessage()
                        ]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Connexion réussie (KLASSCI)',
                        'data' => [
                            'user' => [
                                'id' => $localUser->id,
                                'klassci_id' => $localUser->klassci_id,
                                'name' => $localUser->name,
                                'email' => $localUser->email,
                                'role' => $localUser->role,
                                'role_display_name' => $klassciUser['role_display_name'] ?? '',
                                'avatar' => $klassciUser['avatar'] ?? null,
                                'permissions' => $klassciUser['permissions'] ?? [],
                                'is_admin' => $klassciUser['is_admin'] ?? false,
                                'admin_data' => $klassciUser['admin_data'] ?? null,
                                'enseignant_data' => $klassciUser['enseignant_data'] ?? null,
                                'etudiant_data' => $klassciUser['etudiant_data'] ?? null,
                            ],
                            'token' => $sanctumToken,
                            'token_type' => 'Bearer',
                        ],
                        'meta' => [
                            'klassci_synced' => true,
                            'annee_universitaire_courante' => $klassciResponse['meta']['annee_universitaire_courante'] ?? null,
                            'institution' => app(TenantManager::class)->slug(),
                            'institution_name' => app(TenantManager::class)->get()?->name,
                            'classes_sync' => $syncStats ? [
                                'classes_created' => $syncStats['classes_created'],
                                'students_synced' => $syncStats['students_synced'],
                                'enrollments_created' => $syncStats['enrollments_created'],
                            ] : null,
                        ],
                    ]);
                } catch (\Exception $syncError) {
                    // Si la synchro échoue, retourner directement le token KLASSCI (fallback)
                    Log::error('Erreur synchronisation utilisateur', ['error' => $syncError->getMessage()]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Connexion réussie (KLASSCI - mode dégradé)',
                        'data' => [
                            'user' => [
                                'id' => $klassciUser['id'],
                                'klassci_id' => $klassciUser['id'],
                                'name' => $klassciUser['nom'],
                                'email' => $klassciUser['email'],
                                'role' => $klassciUser['role'],
                                'role_display_name' => $klassciUser['role_display_name'] ?? '',
                                'avatar' => $klassciUser['avatar'] ?? null,
                                'permissions' => $klassciUser['permissions'] ?? [],
                                'is_admin' => $klassciUser['is_admin'] ?? false,
                                'admin_data' => $klassciUser['admin_data'] ?? null,
                                'enseignant_data' => $klassciUser['enseignant_data'] ?? null,
                                'etudiant_data' => $klassciUser['etudiant_data'] ?? null,
                            ],
                            'token' => $klassciToken,
                            'token_type' => 'Bearer',
                        ],
                        'meta' => [
                            'klassci_synced' => false,
                            'direct_klassci_auth' => true,
                            'annee_universitaire_courante' => $klassciResponse['meta']['annee_universitaire_courante'] ?? null,
                            'institution' => app(TenantManager::class)->slug(),
                            'institution_name' => app(TenantManager::class)->get()?->name,
                        ],
                    ]);
                }
            } catch (\Exception $klassciError) {
                // KLASSCI inaccessible et pas d'utilisateur local
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects',
                ], 401);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/auth/me
     * Récupère le profil de l'utilisateur connecté
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié',
                ], 401);
            }

            // Récupérer les données complètes depuis KLASSCI si besoin
            try {
                $klassciMe = $this->klassciService->get('auth/me');
                $userData = $klassciMe['data']['user'] ?? [];
            } catch (\Exception $e) {
                // Si erreur KLASSCI, retourner les données locales
                $userData = [];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'klassci_id' => $user->klassci_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'klassci_data' => $userData,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur récupération profil: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/auth/logout
     * Déconnexion utilisateur (révoque le token actuel)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Révoquer le token Sanctum actuel
            $request->user()->currentAccessToken()->delete();

            // Optionnel : appeler logout KLASSCI
            try {
                $this->klassciService->post('auth/logout', []);
            } catch (\Exception $e) {
                // Ignorer l'erreur KLASSCI, le logout local est prioritaire
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/auth/refresh
     * Rafraîchit le token utilisateur
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Révoquer l'ancien token
            $request->user()->currentAccessToken()->delete();

            // Créer un nouveau token
            $newToken = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token rafraîchi',
                'data' => [
                    'token' => $newToken,
                    'token_type' => 'Bearer',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur refresh token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/auth/check
     * Vérifie la validité du token
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'authenticated' => $user !== null,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ] : null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'authenticated' => false,
            ]);
        }
    }

    /**
     * Synchronise un utilisateur KLASSCI avec la base locale
     *
     * @param array $klassciUser Données utilisateur de KLASSCI
     * @param string $klassciToken Token KLASSCI
     * @return User
     */
    private function syncUserFromKlassci(array $klassciUser, string $klassciToken): User
    {
        $klassciId = $klassciUser['id'];
        $email = $klassciUser['email'];
        $institutionId = app(TenantManager::class)->id();

        // Bypasser le scope institution global pour chercher explicitement
        // par institution_id. Cela évite les conflits quand le même klassci_id
        // ou email existe dans plusieurs institutions (multi-tenant).

        // 1. Chercher par EMAIL + institution_id
        $user = User::withoutGlobalScope('institution')
                    ->where('email', $email)
                    ->where('institution_id', $institutionId)
                    ->first();

        // 2. Si pas trouvé par email, chercher par klassci_id + institution_id
        if (!$user) {
            $user = User::withoutGlobalScope('institution')
                        ->where('klassci_id', $klassciId)
                        ->where('institution_id', $institutionId)
                        ->first();
        }

        $userData = [
            'klassci_id' => $klassciId,
            'name' => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
            'email' => $email,
            'role' => $klassciUser['role'] ?? 'student',
            'klassci_token' => $klassciToken,
            'klassci_data' => json_encode($klassciUser),
            'last_klassci_sync' => now(),
            'institution_id' => $institutionId,
        ];

        if ($user) {
            $user->update($userData);
        } else {
            // Créer un nouvel utilisateur pour cette institution
            $userData['password'] = Hash::make(uniqid());
            $user = User::withoutGlobalScope('institution')->create($userData);

            Log::info('Nouvel utilisateur créé depuis KLASSCI', [
                'user_id' => $user->id,
                'email' => $email,
                'klassci_id' => $klassciId,
                'institution_id' => $institutionId,
            ]);
        }

        // NOUVEAU: Synchroniser les classes pour les étudiants
        if ($user->isStudent()) {
            $this->syncStudentClasses($user, $klassciToken);
        }

        return $user;
    }

    /**
     * Synchronise les classes KLASSCI de l'étudiant dans la BDD locale
     */
    private function syncStudentClasses(User $user, string $klassciToken): void
    {
        try {
            Log::info('Synchronisation des classes pour étudiant', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            // Récupérer le dashboard étudiant depuis KLASSCI
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET'
            );

            // IMPORTANT: L'API retourne 'classe' (singulier), pas 'classes' (pluriel)
            // Un étudiant n'a qu'une seule classe
            $classe = $dashboard['data']['classe'] ?? null;

            if (!$classe) {
                Log::warning('Aucune classe trouvée pour l\'étudiant', [
                    'user_id' => $user->id
                ]);
                return;
            }

            Log::info('Classe KLASSCI récupérée', [
                'user_id' => $user->id,
                'classe_id' => $classe['id'],
                'classe_nom' => $classe['name'] ?? $classe['libelle'] ?? 'N/A'
            ]);

            // Supprimer les anciennes classes (pour éviter les doublons)
            $user->klassciClasses()->delete();

            // Sauvegarder la classe
            \App\Models\UserClass::create([
                'user_id' => $user->id,
                'klassci_classe_id' => $classe['id'],
                'classe_nom' => $classe['name'] ?? $classe['libelle'] ?? null,
                'classe_libelle' => $classe['libelle'] ?? $classe['name'] ?? null,
                'classe_data' => $classe,
                'synced_at' => now(),
            ]);

            Log::info('Classe synchronisée avec succès', [
                'user_id' => $user->id,
                'classe_id' => $classe['id']
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la synchronisation des classes', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Ne pas bloquer la connexion si la sync échoue
        }
    }
}
