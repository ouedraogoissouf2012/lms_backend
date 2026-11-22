<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\KlassciEtablissementIntrouvableException;
use App\Http\Presenters\AuthResponsePresenter;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Http\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * #521 — orchestration du login (locale puis KLASSCI).
 */
final class LoginOrchestrator
{
    public function __construct(
        private readonly LocalLmsAuthenticator $localAuth,
        private readonly KlassciTenantDiscovery $tenantDiscovery,
        private readonly KlassciAuthClient $klassciAuthClient,
        private readonly KlassciUserSynchronizer $userSync,
        private readonly AuthResponsePresenter $presenter,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly RosterAuthorityFactory $rosterAuthorities,
        private readonly PorteDuCatalogueLocal $catalogue,
        private readonly KlassciConfigResolver $cibles,
    ) {}

    public function attemptLocal(string $username, string $password): ?JsonResponse
    {
        $user = $this->localAuth->attemptLocalAuth($username, $password);
        if ($user === null) {
            return null;
        }

        $klassciResponse = $this->refreshLinkedKlassciLogin($user, $username, $password);
        if ($klassciResponse !== null) {
            return $klassciResponse;
        }

        $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;
        $this->auditLogger->logAuthEvent('login', $user, ['method' => 'local']);
        $institution = $user->institution;

        return $this->presenter->successfulLocal(
            $user,
            $token,
            $institution,
            $this->rosterAuthorities->forInstitution($institution)->allowsLocalEnrolment(),
            $this->catalogue->ouvertePour($institution),
        );
    }

    public function attemptKlassci(string $username, string $password): JsonResponse
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

        $this->auditLogger->logAuthEvent('login_failed', null, ['username' => $username]);

        return $this->presenter->invalidCredentials();
    }

    private function refreshLinkedKlassciLogin(User $user, string $username, string $password): ?JsonResponse
    {
        if ($user->klassci_id === null || $user->institution_id === null) {
            return null;
        }

        $institution = Institution::find($user->institution_id);
        if (! $institution instanceof Institution) {
            return null;
        }

        // #1318 — la cible de l'école, et elle seule : une école autonome, inactive ou
        // d'URL inexploitable n'en a aucune, et le mot de passe saisi ne part pas. La
        // connexion locale continue.
        $cible = $this->cibles->cibleDeLEcole($institution);
        if ($cible === null) {
            return null;
        }

        $tenantUrl = rtrim($cible, '/');

        $payload = $this->klassciAuthClient->attemptLogin($tenantUrl, $username, $password);
        if ($payload === null) {
            $this->logger->warning('Renouvellement token KLASSCI impossible, fallback local', [
                'user_id' => $user->id,
                'institution_id' => $institution->id,
            ]);

            return null;
        }

        return $this->buildKlassciSuccessResponse($payload, [
            'code' => (string) $institution->slug,
            'api_base_url' => $tenantUrl,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{code: string, api_base_url: string}  $tenant
     */
    private function buildKlassciSuccessResponse(array $payload, array $tenant): JsonResponse
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $klassciUser = is_array($data['user'] ?? null) ? $data['user'] : [];
        $klassciToken = is_string($data['token'] ?? null) ? $data['token'] : '';
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        // #1181 : l'établissement a pu être désactivé ou supprimé depuis la découverte
        // des tenants. Aucun compte d'école ne se crée ni ne se restaure sans école.
        $institution = Institution::where('slug', $tenant['code'])->where('is_active', true)->first();
        if (! $institution instanceof Institution) {
            $this->logger->warning('Connexion KLASSCI refusée : établissement introuvable ou inactif', ['tenant' => $tenant['code']]);

            throw new KlassciEtablissementIntrouvableException('Établissement KLASSCI introuvable ou inactif.');
        }

        $localUser = $this->userSync->sync($klassciUser, $klassciToken, $tenant['api_base_url'], $institution);
        $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        $this->auditLogger->logAuthEvent('login', $localUser, [
            'method' => 'klassci',
            'tenant' => $tenant['code'],
        ]);

        return $this->presenter->successfulKlassci(
            $localUser,
            $sanctumToken,
            $klassciUser,
            $meta,
            $tenant,
            $this->rosterAuthorities->forInstitution($institution)->allowsLocalEnrolment(),
            $this->catalogue->ouvertePour($institution),
        );
    }
}
