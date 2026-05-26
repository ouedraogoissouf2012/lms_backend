<?php

namespace App\Http\Controllers\API;

use App\Enums\Role;
use App\Http\Controllers\AuthenticatedController;
use App\Models\Institution;
use App\Services\Auth\LocalLmsAuthenticator;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Controller d'authentification — orchestrateur fin.
 *
 * ## Issue #120 — Refactor 528 → ~180 lignes
 *
 * Avant ce refactor, ce controller faisait 528 lignes et mélangeait 5
 * responsabilités (discovery multi-tenant, auth locale, auth KLASSCI,
 * sync user, sessions). Violations §1.1 + §5 + §1.6 du manifeste.
 *
 * Refactor en orchestrateur fin qui délègue à 4 collaborateurs DIP :
 *
 *   - {@see \App\Services\Auth\LocalLmsAuthenticator} — auth locale (Hash::check)
 *   - {@see \App\Services\Klassci\Auth\KlassciTenantDiscovery} — Http::pool multi-tenant
 *   - {@see \App\Services\Klassci\Auth\KlassciAuthClient} — POST /auth/login sur 1 tenant
 *   - {@see \App\Services\Klassci\Auth\KlassciUserSynchronizer} — DB transaction sync
 *
 * Chaque méthode publique de ce controller fait ≤ 40 lignes (§5).
 *
 * @see .claude/specs/auth-controller-refactor/design.md
 */
class AuthController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private LocalLmsAuthenticator $localAuth,
        private KlassciTenantDiscovery $tenantDiscovery,
        private KlassciAuthClient $klassciAuthClient,
        private KlassciUserSynchronizer $userSync,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Flow en 3 étapes :
     *   1. Auth locale (supradmin LMS, users avec password local).
     *   2. Discovery KLASSCI multi-tenant (parallèle via Http::pool).
     *   3. Login KLASSCI sur les tenants matchés + sync user local.
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
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $username = $request->input('username');
            $password = $request->input('password');
            if (!is_string($username) || !is_string($password)) {
                return $this->invalidCredentialsResponse();
            }

            // Étape 1 — Auth locale (supradmin, comptes avec password local).
            $localUser = $this->localAuth->attemptLocalAuth($username, $password);
            if ($localUser !== null) {
                return $this->buildLocalLoginResponse($localUser);
            }

            // Étape 2 — Discovery multi-tenant KLASSCI.
            $matchingTenants = $this->tenantDiscovery->findMatchingTenants($username);
            if ($matchingTenants === []) {
                return $this->invalidCredentialsResponse();
            }

            // Étape 3 — Login KLASSCI sur les tenants matchés (try-next-tenant).
            foreach ($matchingTenants as $tenant) {
                $payload = $this->klassciAuthClient->attemptLogin($tenant['api_base_url'], $username, $password);
                if ($payload === null) {
                    continue;
                }

                return $this->buildKlassciLoginResponse($payload, $tenant);
            }

            return $this->invalidCredentialsResponse();
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion.',
            ], 500);
        }
    }

    /**
     * GET /api/auth/me — Récupère le profil de l'utilisateur connecté.
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            try {
                $klassciMe = $this->klassciService->get('auth/me');
                $userData  = $klassciMe['data']['user'] ?? [];
            } catch (\Exception) {
                $userData = [];
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'           => $user->id,
                    'klassci_id'   => $user->klassci_id,
                    'name'         => $user->name,
                    'email'        => $user->email,
                    'role'         => $user->role,
                    'klassci_data' => $userData,
                ],
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil.',
            ], 500);
        }
    }

    /**
     * POST /api/auth/logout — Révoque le token Sanctum actuel.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authenticatedUser($request)->currentAccessToken()->delete();

            try {
                $this->klassciService->post('auth/logout', []);
            } catch (\Exception) {
                // Logout KLASSCI optionnel — le logout local est prioritaire.
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie',
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion.',
            ], 500);
        }
    }

    /**
     * POST /api/auth/refresh — Génère un nouveau token Sanctum.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $user->currentAccessToken()->delete();
            $newToken = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token rafraîchi',
                'data'    => [
                    'token'      => $newToken,
                    'token_type' => 'Bearer',
                ],
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du renouvellement du token.',
            ], 500);
        }
    }

    /**
     * GET /api/auth/check — Vérifie la validité du token.
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success'       => true,
                'authenticated' => $user !== null,
                'user'          => $user !== null ? [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ] : null,
            ]);
        } catch (\Exception) {
            return response()->json([
                'success'       => false,
                'authenticated' => false,
            ]);
        }
    }

    /**
     * Construit la réponse de succès pour un login local (sans tenant KLASSCI).
     */
    private function buildLocalLoginResponse(\App\Models\User $user): JsonResponse
    {
        $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data'    => [
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
                'klassci_synced'   => false,
                'is_supradmin'     => $user->asRoleEnum() === Role::Supradmin,
                'institution'      => null,
                'institution_name' => null,
            ],
        ]);
    }

    /**
     * Construit la réponse de succès pour un login KLASSCI (avec sync user local).
     *
     * @param  array<string, mixed>  $payload  Payload `/auth/login` KLASSCI
     * @param  array{code: string, api_base_url: string}  $tenant
     */
    private function buildKlassciLoginResponse(array $payload, array $tenant): JsonResponse
    {
        $data         = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $klassciUser  = is_array($data['user'] ?? null) ? $data['user'] : [];
        $klassciToken = is_string($data['token'] ?? null) ? $data['token'] : '';

        $institution = Institution::where('slug', $tenant['code'])->first();
        $localUser   = $this->userSync->sync($klassciUser, $klassciToken, $tenant['api_base_url'], $institution);
        $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data'    => [
                'user' => [
                    'id'                => $localUser->id,
                    'klassci_id'        => $localUser->klassci_id,
                    'name'              => $localUser->name,
                    'email'             => $localUser->email,
                    'role'              => $localUser->role,
                    'role_display_name' => $klassciUser['role_display_name'] ?? '',
                    'avatar'            => $klassciUser['avatar'] ?? null,
                    'permissions'       => $klassciUser['permissions'] ?? [],
                    'is_admin'          => $klassciUser['is_admin'] ?? false,
                    'admin_data'        => $klassciUser['admin_data'] ?? null,
                    'enseignant_data'   => $klassciUser['enseignant_data'] ?? null,
                    'etudiant_data'     => $klassciUser['etudiant_data'] ?? null,
                ],
                'token'      => $sanctumToken,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'klassci_synced'               => true,
                'institution'                  => $tenant['code'],
                'institution_name'             => $klassciUser['admin_data']['etablissement'] ?? $tenant['code'],
                'annee_universitaire_courante' => $payload['meta']['annee_universitaire_courante'] ?? null,
            ],
        ]);
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Identifiants incorrects',
        ], 401);
    }
}
