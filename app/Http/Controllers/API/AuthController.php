<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use App\Services\ClasseSyncService;
use App\Services\TenantManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
     * Liste statique des tenants KLASSCI connus.
     * Sera remplacée par un appel au master registry quand disponible.
     */
    private function getKlassciTenants(): array
    {
        return [
            ['code' => 'presentation', 'api_base_url' => 'http://presentation.klassci.com/api/lms'],
            ['code' => 'hetec',        'api_base_url' => 'https://hetec.klassci.com/api/lms'],
            ['code' => 'esbtp-abidjan','api_base_url' => 'https://esbtp-abidjan.klassci.com/api/lms'],
            ['code' => 'esbtp-yakro',  'api_base_url' => 'https://esbtp-yakro.klassci.com/api/lms'],
        ];
    }

    /**
     * Cherche sur quel tenant KLASSCI un identifiant (username/email) existe.
     * Appels check-user en parallèle sur tous les tenants (endpoint public).
     */
    private function findTenantForUser(string $identifier): ?array
    {
        $tenants = $this->getKlassciTenants();

        $responses = Http::pool(function (Pool $pool) use ($tenants, $identifier) {
            foreach ($tenants as $tenant) {
                $pool->as($tenant['code'])
                    ->withoutVerifying()
                    ->timeout(10)
                    ->post($tenant['api_base_url'] . '/auth/check-user', [
                        'identifier' => $identifier,
                    ]);
            }
        });

        foreach ($tenants as $tenant) {
            $response = $responses[$tenant['code']] ?? null;
            if ($response && $response->successful()) {
                $data = $response->json();
                if ($data['data']['found'] ?? false) {
                    Log::info('Tenant trouvé pour utilisateur', [
                        'identifier' => $identifier,
                        'tenant' => $tenant['code'],
                    ]);
                    return $tenant;
                }
            }
        }

        return null;
    }

    /**
     * POST /api/auth/login
     * Connexion utilisateur — détection automatique du tenant KLASSCI
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // 1. Authentification locale (supradmin, comptes LMS internes)
            try {
                $user = User::withoutGlobalScope('institution')
                            ->where('email', $request->username)
                            ->orWhere('name', $request->username)
                            ->first();

                if ($user && Hash::check($request->password, $user->password)) {
                    $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

                    return response()->json([
                        'success' => true,
                        'message' => 'Connexion réussie',
                        'data' => [
                            'user' => [
                                'id'         => $user->id,
                                'klassci_id' => $user->klassci_id,
                                'name'       => $user->name,
                                'email'      => $user->email,
                                'role'       => $user->role,
                            ],
                            'token'      => $token,
                            'token_type' => 'Bearer',
                        ],
                        'meta' => [
                            'klassci_synced' => false,
                            'is_supradmin'   => $user->role === 'supradmin',
                            'institution'    => null,
                            'institution_name' => null,
                        ],
                    ]);
                }
            } catch (\Exception $dbError) {
                Log::warning('DB locale non accessible, passage à KLASSCI', [
                    'error' => $dbError->getMessage()
                ]);
            }

            // 2. Détection automatique du tenant KLASSCI (check-user en parallèle)
            $tenant = $this->findTenantForUser($request->username);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects',
                ], 401);
            }

            // 3. Login sur le tenant trouvé (endpoint public, pas de token système)
            try {
                $loginResponse = Http::withoutVerifying()
                    ->timeout(30)
                    ->post($tenant['api_base_url'] . '/auth/login', [
                        'username' => $request->username,
                        'password' => $request->password,
                    ]);

                if (!$loginResponse->successful()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Identifiants incorrects',
                    ], 401);
                }

                $klassciResponse = $loginResponse->json();

                if (!($klassciResponse['success'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Identifiants incorrects',
                    ], 401);
                }

                $klassciUser  = $klassciResponse['data']['user'];
                $klassciToken = $klassciResponse['data']['token'];
                $tenantUrl    = $tenant['api_base_url'];

                // 4. Synchroniser l'utilisateur localement avec son tenant_url
                $localUser = $this->syncUserFromKlassci($klassciUser, $klassciToken, $tenantUrl);
                $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

                // 5. Synchroniser les classes (non-bloquant)
                try {
                    $this->classeSyncService->syncUserClasses($klassciToken, $localUser->role);
                } catch (\Exception $syncError) {
                    Log::warning('Erreur sync classes au login', ['error' => $syncError->getMessage()]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Connexion réussie',
                    'data' => [
                        'user' => [
                            'id'               => $localUser->id,
                            'klassci_id'       => $localUser->klassci_id,
                            'name'             => $localUser->name,
                            'email'            => $localUser->email,
                            'role'             => $localUser->role,
                            'role_display_name'=> $klassciUser['role_display_name'] ?? '',
                            'avatar'           => $klassciUser['avatar'] ?? null,
                            'permissions'      => $klassciUser['permissions'] ?? [],
                            'is_admin'         => $klassciUser['is_admin'] ?? false,
                            'admin_data'       => $klassciUser['admin_data'] ?? null,
                            'enseignant_data'  => $klassciUser['enseignant_data'] ?? null,
                            'etudiant_data'    => $klassciUser['etudiant_data'] ?? null,
                        ],
                        'token'      => $sanctumToken,
                        'token_type' => 'Bearer',
                    ],
                    'meta' => [
                        'klassci_synced'              => true,
                        'institution'                 => $tenant['code'],
                        'institution_name'            => $klassciUser['admin_data']['etablissement'] ?? $tenant['code'],
                        'annee_universitaire_courante'=> $klassciResponse['meta']['annee_universitaire_courante'] ?? null,
                    ],
                ]);

            } catch (\Exception $e) {
                Log::error('Erreur login KLASSCI', ['error' => $e->getMessage(), 'tenant' => $tenant['code']]);
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
    private function syncUserFromKlassci(array $klassciUser, string $klassciToken, string $tenantUrl = ''): User
    {
        $klassciId = $klassciUser['id'];
        $email     = $klassciUser['email'];

        // Clé d'unicité : (klassci_id, tenant_url) — chaque tenant a sa propre numérotation
        $user = User::withoutGlobalScope('institution')
                    ->where('klassci_id', $klassciId)
                    ->where('klassci_tenant_url', $tenantUrl)
                    ->first();

        if (!$user) {
            $user = User::withoutGlobalScope('institution')
                        ->where('email', $email)
                        ->where('klassci_tenant_url', $tenantUrl)
                        ->first();
        }

        $userData = [
            'klassci_id'        => $klassciId,
            'name'              => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
            'email'             => $email,
            'role'              => $klassciUser['role'] ?? 'etudiant',
            'klassci_token'     => $klassciToken,
            'klassci_tenant_url'=> $tenantUrl,
            'klassci_data'      => json_encode(array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])),
            'last_klassci_sync' => now(),
            'institution_id'    => null, // Plus utilisé pour l'isolation — on utilise klassci_tenant_url
        ];

        if ($user) {
            $user->update($userData);
        } else {
            $userData['password'] = Hash::make(uniqid());
            $user = User::withoutGlobalScope('institution')->create($userData);

            Log::info('Nouvel utilisateur créé depuis KLASSCI', [
                'user_id'    => $user->id,
                'email'      => $email,
                'klassci_id' => $klassciId,
                'tenant'     => $tenantUrl,
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
