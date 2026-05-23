<?php

namespace App\Http\Controllers\API;

use App\Enums\Role;
use App\Http\Controllers\AuthenticatedController;
use App\Services\KlassciProxyService;
use App\Services\ClasseSyncService;
use App\Services\TenantManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
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
class AuthController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private ClasseSyncService $classeSyncService
    ) {}

    /**
     * Retourne les tenants KLASSCI actifs depuis la table institutions.
     */
    private function getKlassciTenants(): array
    {
        return \App\Models\Institution::where('is_active', true)
            ->whereNotNull('klassci_api_url')
            ->get()
            ->map(fn($inst) => [
                'code'         => $inst->slug,
                'api_base_url' => rtrim($inst->klassci_api_url, '/'),
            ])
            ->toArray();
    }

    /**
     * Cherche tous les tenants KLASSCI où un identifiant (username/email) existe.
     * Appels check-user en parallèle sur tous les tenants (endpoint public).
     * Retourne la liste de tous les tenants qui ont trouvé l'utilisateur.
     */
    private function findTenantsForUser(string $identifier): array
    {
        $tenants = $this->getKlassciTenants();

        $verifySSL = config('services.klassci.ssl_verify', true);
        $responses = Http::pool(function (Pool $pool) use ($tenants, $identifier, $verifySSL) {
            foreach ($tenants as $tenant) {
                $req = $pool->as($tenant['code'])->timeout(10);
                if (!$verifySSL) {
                    $req = $req->withoutVerifying();
                }
                $req->post($tenant['api_base_url'] . '/auth/check-user', [
                    'identifier' => $identifier,
                ]);
            }
        });

        $matching = [];
        foreach ($tenants as $tenant) {
            $response = $responses[$tenant['code']] ?? null;
            if ($response && $response->successful()) {
                $data = $response->json();
                if ($data['data']['found'] ?? false) {
                    $matching[] = $tenant;
                }
            }
        }

        Log::info('Tenants trouvés pour utilisateur', [
            'identifier' => $identifier,
            'count'      => count($matching),
            'tenants'    => array_column($matching, 'code'),
        ]);

        return $matching;
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
                'password' => 'required|string|min:8',
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
                            'is_supradmin'   => $user->asRoleEnum() === Role::Supradmin,
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

            // 2. Détection automatique des tenants KLASSCI (check-user en parallèle sur tous)
            $matchingTenants = $this->findTenantsForUser($request->username);

            if (empty($matchingTenants)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects',
                ], 401);
            }

            // 3. Essayer le login sur chaque tenant trouvé jusqu'à succès
            // (cas où le même identifiant existe sur plusieurs écoles avec des mots de passe différents)
            foreach ($matchingTenants as $tenant) {
                try {
                    $http = Http::timeout(30);
                    if (!config('services.klassci.ssl_verify', true)) {
                        $http = $http->withoutVerifying();
                    }
                    $loginResponse = $http->post($tenant['api_base_url'] . '/auth/login', [
                        'username' => $request->username,
                        'password' => $request->password,
                    ]);

                    if (!$loginResponse->successful()) {
                        Log::info('Login échoué sur tenant, on essaie le suivant', ['tenant' => $tenant['code']]);
                        continue;
                    }

                    $klassciResponse = $loginResponse->json();

                    if (!($klassciResponse['success'] ?? false)) {
                        Log::info('Login refusé sur tenant, on essaie le suivant', ['tenant' => $tenant['code']]);
                        continue;
                    }

                    $klassciUser  = $klassciResponse['data']['user'];
                    $klassciToken = $klassciResponse['data']['token'];
                    $tenantUrl    = $tenant['api_base_url'];

                    // 4. Récupérer l'institution en DB pour avoir son ID réel
                    $institution = \App\Models\Institution::where('slug', $tenant['code'])->first();

                    // 5. Synchroniser l'utilisateur localement avec le bon institution_id
                    $localUser = $this->syncUserFromKlassci($klassciUser, $klassciToken, $tenantUrl, $institution);
                    $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

                    // 6. Synchroniser les classes (non-bloquant)
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
                    Log::warning('Erreur login sur tenant', ['tenant' => $tenant['code'], 'error' => $e->getMessage()]);
                    continue;
                }
            }

            // Aucun tenant n'a accepté les identifiants
            return response()->json([
                'success' => false,
                'message' => 'Identifiants incorrects',
            ], 401);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion.',
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
            $user = $this->authenticatedUser($request);

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
                'message' => 'Erreur lors de la récupération du profil.',
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
            $this->authenticatedUser($request)->currentAccessToken()->delete();

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
                'message' => 'Erreur lors de la déconnexion.',
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
            $user = $this->authenticatedUser($request);

            // Révoquer l'ancien token
            $user->currentAccessToken()->delete();

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
                'message' => 'Erreur lors du renouvellement du token.',
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
     * Synchronise un utilisateur KLASSCI avec la base locale.
     * L'isolation est garantie par institution_id — chaque école a son propre espace.
     *
     * @param array $klassciUser Données utilisateur de KLASSCI
     * @param string $klassciToken Token KLASSCI
     * @param string $tenantUrl URL de base du tenant KLASSCI
     * @param \App\Models\Institution|null $institution Institution correspondante en DB
     * @return User
     */
    private function syncUserFromKlassci(array $klassciUser, string $klassciToken, string $tenantUrl = '', ?\App\Models\Institution $institution = null): User
    {
        $klassciId     = $klassciUser['id'];
        $email         = $klassciUser['email'];
        $institutionId = $institution?->id;

        return DB::transaction(function () use ($klassciId, $email, $institutionId, $klassciUser, $klassciToken, $tenantUrl) {
            // Recherche par (klassci_id, institution_id) — la clé unique de la DB
            $user = User::withoutGlobalScope('institution')
                        ->where('klassci_id', $klassciId)
                        ->where('institution_id', $institutionId)
                        ->first();

            // Fallback par email si klassci_id a changé (rare mais possible)
            if (!$user) {
                $user = User::withoutGlobalScope('institution')
                            ->where('email', $email)
                            ->where('institution_id', $institutionId)
                            ->first();
            }

            $klassciRole = $klassciUser['role'] ?? 'etudiant';

            // Champs propagés sur CHAQUE login (CREATE + UPDATE).
            // Email reste syncé au login parce que l'utilisateur engage activement
            // sa session KLASSCI à cet instant — l'asymétrie avec le re-sync passif
            // est détaillée dans `.claude/specs/critical-05-klassci-role-separation/design.md` §4.
            $commonData = [
                'klassci_id'         => $klassciId,
                'name'               => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
                'email'              => $email,
                'klassci_role'       => $klassciRole,
                'klassci_token'      => $klassciToken,
                'klassci_tenant_url' => $tenantUrl,
                'klassci_data'       => json_encode(array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])),
                'last_klassci_sync'  => now(),
                'institution_id'     => $institutionId,
            ];

            if ($user) {
                // SÉCURITÉ — REQ-3 de la spec critical-05 :
                // Pour un user existant, `role` LMS reste figé. Seul `klassci_role`
                // capture la valeur de KLASSCI. Le contrôle de `role` LMS appartient
                // exclusivement à l'administration LMS.
                $user->update($commonData);
            } else {
                // SÉCURITÉ — initialisation autorisée d'un nouvel utilisateur.
                //   • `role` LMS — REQ-3 spec critical-05 : seul chemin où KLASSCI peut
                //                  initialiser l'autorisation LMS d'un user découvert
                //   • `klassci_enseignant_id` — REQ-3 spec klassci-enseignant-id-separation :
                //                  initialisation write-once. Plus jamais réécrit (ni au login
                //                  d'un user existant, ni au re-sync passif 24h). Source d'autorité
                //                  unique pour l'ownership évaluations (issue #119).
                $user = User::withoutGlobalScope('institution')->create(array_merge($commonData, [
                    'role'                  => $klassciRole,
                    'klassci_enseignant_id' => isset($klassciUser['enseignant_id']) && is_numeric($klassciUser['enseignant_id'])
                        ? (int) $klassciUser['enseignant_id']
                        : null,
                    'password'              => Hash::make(uniqid()),
                ]));

                Log::info('Nouvel utilisateur créé depuis KLASSCI', [
                    'user_id'        => $user->id,
                    'email'          => $email,
                    'klassci_id'     => $klassciId,
                    'institution_id' => $institutionId,
                    'tenant'         => $tenantUrl,
                ]);
            }

            // Synchroniser les classes pour les étudiants
            if ($user->isStudent()) {
                $this->syncStudentClasses($user, $klassciToken);
            }

            return $user;
        });
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
