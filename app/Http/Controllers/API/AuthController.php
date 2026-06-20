<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Presenters\AuthResponsePresenter;
use App\Http\Requests\LoginRequest;
use App\Models\Institution;
use App\Models\User;
use App\Services\Auth\LocalLmsAuthenticator;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Audit\AuditLogger;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Controller d'authentification — orchestrateur fin (≤ 200 lignes §5).
 *
 * ## Issue #120 — Refactor 528 → ≤ 200 lignes
 *
 * Avant ce refactor, ce controller faisait 528 lignes et mélangeait 5
 * responsabilités. Refactor en orchestrateur fin qui délègue à 4 collaborateurs
 * DIP-friendly + 1 presenter de réponses JSON + 1 FormRequest.
 *
 * @see app/Services/Auth/LocalLmsAuthenticator.php
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Services/Klassci/Auth/KlassciAuthClient.php
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 * @see app/Http/Presenters/AuthResponsePresenter.php
 * @see app/Http/Requests/LoginRequest.php
 * @see .claude/specs/auth-controller-refactor/
 */
class AuthController extends AuthenticatedController
{
    public function __construct(
        private KlassciProxyService $klassciService,
        private LocalLmsAuthenticator $localAuth,
        private KlassciTenantDiscovery $tenantDiscovery,
        private KlassciAuthClient $klassciAuthClient,
        private KlassciUserSynchronizer $userSync,
        private AuthResponsePresenter $presenter,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {}

    /**
     * POST /api/auth/login — Auth locale puis fallback KLASSCI multi-tenant.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $username = $request->username();
            $password = $request->password();

            $localResponse = $this->attemptLocalLogin($username, $password);
            if ($localResponse !== null) {
                return $localResponse;
            }

            return $this->attemptKlassciLogin($username, $password);
        } catch (\App\Exceptions\KlassciAccountConflictException $e) {
            // Anomalie de données (email déjà pris par un autre compte) → 409
            // propre. Déjà loggé en détail par le synchronizer ; ici on trace
            // juste le blocage du login pour corrélation.
            $this->logger->warning('Login bloqué — conflit de compte KLASSCI', [
                'username' => $request->username(),
            ]);

            return $this->presenter->accountConflict();
        } catch (\App\Exceptions\KlassciUnavailableException $e) {
            // #243 : KLASSCI totalement injoignable → 503 (panne externe
            // temporaire), pas un 500 ni un 401. L'utilisateur peut réessayer.
            $this->logger->warning('Login indisponible — KLASSCI injoignable', [
                'username' => $request->username(),
            ]);

            return $this->presenter->authServiceUnavailable();
        } catch (\Throwable $e) {
            // #242 : tracer l'exception côté serveur AVANT de renvoyer un 500
            // générique. Sans ce log, les vrais plantages du login (ex. table
            // audit absente — incident 2026-06-20) restaient invisibles dans
            // laravel.log. On ne logue JAMAIS le mot de passe ; le username
            // (déjà fourni par l'utilisateur) aide à corréler l'incident.
            $this->logger->error('Échec inattendu du login', [
                'username' => $request->username(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->presenter->loginError();
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
                $userData  = is_array($klassciMe['data']['user'] ?? null) ? $klassciMe['data']['user'] : [];
            } catch (\Exception) {
                $userData = [];
            }

            return $this->presenter->profile($user, $userData);
        } catch (\Exception) {
            return $this->presenter->profileError();
        }
    }

    /**
     * POST /api/auth/logout — Révoque le token Sanctum actuel.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $this->auditLogger->logAuthEvent('logout', $user->id);
            $user->currentAccessToken()->delete();

            try {
                $this->klassciService->post('auth/logout', []);
            } catch (\Exception) {
                // Logout KLASSCI optionnel — le logout local est prioritaire.
            }

            return $this->presenter->logoutSuccess();
        } catch (\Exception) {
            return $this->presenter->logoutError();
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

            return $this->presenter->tokenRefreshed($newToken);
        } catch (\Exception) {
            return $this->presenter->refreshError();
        }
    }

    /**
     * GET /api/auth/check — Vérifie la validité du token.
     */
    public function check(Request $request): JsonResponse
    {
        try {
            return $this->presenter->checkResult($request->user());
        } catch (\Exception) {
            return $this->presenter->checkUnauthenticated();
        }
    }

    /**
     * Étape 1 du flow login : auth locale (supradmin, users avec password local).
     * Retourne la JsonResponse de succès si match, sinon null pour continuer.
     */
    private function attemptLocalLogin(string $username, string $password): ?JsonResponse
    {
        $user = $this->localAuth->attemptLocalAuth($username, $password);
        if ($user === null) {
            return null;
        }

        $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        $this->auditLogger->logAuthEvent('login', $user->id, ['method' => 'local']);

        return $this->presenter->successfulLocal($user, $token);
    }

    /**
     * Étapes 2+3 du flow login : discovery multi-tenant + auth KLASSCI + sync.
     */
    private function attemptKlassciLogin(string $username, string $password): JsonResponse
    {
        $matchingTenants = $this->tenantDiscovery->findMatchingTenants($username);
        if ($matchingTenants === []) {
            return $this->presenter->invalidCredentials();
        }

        foreach ($matchingTenants as $tenant) {
            $payload = $this->klassciAuthClient->attemptLogin($tenant['api_base_url'], $username, $password);
            if ($payload === null) {
                continue;
            }

            return $this->buildKlassciSuccessResponse($payload, $tenant);
        }

        // Aucun tenant n'a validé les identifiants → échec d'authentification.
        $this->auditLogger->logAuthEvent('login_failed', null, ['username' => $username]);

        return $this->presenter->invalidCredentials();
    }

    /**
     * Synchronise le user local + génère le token Sanctum + délègue la
     * construction de la JsonResponse au presenter.
     *
     * @param  array<string, mixed>  $payload  Payload `/auth/login` KLASSCI
     * @param  array{code: string, api_base_url: string}  $tenant
     */
    private function buildKlassciSuccessResponse(array $payload, array $tenant): JsonResponse
    {
        $data         = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $klassciUser  = is_array($data['user'] ?? null) ? $data['user'] : [];
        $klassciToken = is_string($data['token'] ?? null) ? $data['token'] : '';
        $meta         = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $institution  = Institution::where('slug', $tenant['code'])->first();
        $localUser    = $this->userSync->sync($klassciUser, $klassciToken, $tenant['api_base_url'], $institution);
        $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        $this->auditLogger->logAuthEvent('login', $localUser->id, ['method' => 'klassci', 'tenant' => $tenant['code']]);

        return $this->presenter->successfulKlassci($localUser, $sanctumToken, $klassciUser, $meta, $tenant);
    }
}
