<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Presenters\AuthResponsePresenter;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
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
    ) {
    }

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
        $this->auditLogger->logAuthEvent('login', $user->id, ['method' => 'local']);

        return $this->presenter->successfulLocal($user, $token);
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
        if (! $institution instanceof Institution || ! $institution->is_active) {
            return null;
        }

        $tenantUrl = rtrim((string) $institution->klassci_api_url, '/');
        if ($tenantUrl === '') {
            return null;
        }

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

        $institution = Institution::where('slug', $tenant['code'])->first();
        $localUser = $this->userSync->sync($klassciUser, $klassciToken, $tenant['api_base_url'], $institution);
        $sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

        $this->auditLogger->logAuthEvent('login', $localUser->id, [
            'method' => 'klassci',
            'tenant' => $tenant['code'],
        ]);

        return $this->presenter->successfulKlassci($localUser, $sanctumToken, $klassciUser, $meta, $tenant);
    }
}
