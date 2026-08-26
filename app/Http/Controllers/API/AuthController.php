<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Presenters\AuthResponsePresenter;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginOrchestrator;
use App\Services\Klassci\Data\KlassciDataWhitelist;
use App\Services\Audit\AuditLogger;
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
        private LoginOrchestrator $login,
        private AuthResponsePresenter $presenter,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
        private KlassciDataWhitelist $klassciDataWhitelist,
    ) {}

    /**
     * POST /api/auth/login — Auth locale puis fallback KLASSCI multi-tenant.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $username = $request->username();
            $password = $request->password();

            $localResponse = $this->login->attemptLocal($username, $password);
            if ($localResponse !== null) {
                return $localResponse;
            }

            return $this->login->attemptKlassci($username, $password);
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

            // #477 (vecteur B) : whitelist du payload KLASSCI LIVE avant exposition
            // frontend (un KLASSCI compromis ne peut injecter de clés dans la réponse).
            $userData = $this->klassciDataWhitelist->filter($this->fetchLiveKlassciProfile($user));

            return $this->presenter->profile($user, $userData);
        } catch (\Exception) {
            return $this->presenter->profileError();
        }
    }

    /**
     * #568 — Profil KLASSCI LIVE isolé PAR utilisateur : variante user-token-aware
     * (clé cache dérivée du hash du token). L'ancien `get('auth/me')` cachait sous
     * une clé tenant-globale → le profil du 1er appelant fuitait à tout le tenant.
     *
     * @return array<string, mixed>  Payload `data.user`, ou [] si indisponible.
     */
    private function fetchLiveKlassciProfile(User $user): array
    {
        $klassciToken = $user->klassci_token;
        if (!is_string($klassciToken) || $klassciToken === '') {
            return []; // Pas de token perso (auth locale / institution) → pas de profil KLASSCI perso.
        }

        try {
            $klassciMe = $this->klassciService->requestWithUserToken($klassciToken, 'auth/me', 'GET');

            return is_array($klassciMe['data']['user'] ?? null) ? $klassciMe['data']['user'] : [];
        } catch (\Exception) {
            return [];
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
}
