<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\KlassciCompteRefuse;
use App\Exceptions\KlassciEtablissementIntrouvableException;
use App\Exceptions\KlassciUnavailableException;
use App\Http\Presenters\AuthResponsePresenter;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Enseignant\IdDansLAnnuaire;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\Klassci\KlassciConfigResolver;
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
        private readonly LesCapacitesDuCompte $capacites,
        private readonly KlassciConfigResolver $cibles,
        private readonly IdDansLAnnuaire $idDansLAnnuaire,
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
            $this->capacites->de($institution),
            $this->idDansLAnnuaire->de($user),
        );
    }

    public function attemptKlassci(string $username, string $password): JsonResponse
    {
        $matchingTenants = $this->tenantDiscovery->findMatchingTenants($username);
        if ($matchingTenants === []) {
            return $this->presenter->invalidCredentials();
        }

        $panne = null;
        $refus = null;
        foreach ($matchingTenants as $tenant) {
            try {
                $payload = $this->klassciAuthClient->attemptLogin($tenant['api_base_url'], $username, $password);
            } catch (KlassciUnavailableException $e) {
                $panne = $e;

                continue;
            } catch (KlassciCompteRefuse $e) {
                $refus = $e;

                continue;
            }
            if ($payload === null) {
                continue;
            }

            return $this->buildKlassciSuccessResponse($payload, $tenant);
        }

        // #1739 — une école qui a refusé le COMPTE a jugé le mot de passe juste : son motif
        // l'emporte sur une panne et sur un refus d'identifiants ailleurs.
        if ($refus !== null) {
            return $this->compteRefuse($refus, null, $username);
        }

        // #687 — une école trouvée qui n'a pas pu juger le mot de passe : seul un refus
        // de TOUTES vaut « identifiants incorrects ». Sinon, c'est une panne (503).
        if ($panne !== null) {
            throw $panne;
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

        try {
            $payload = $this->klassciAuthClient->attemptLogin($tenantUrl, $username, $password);
        } catch (KlassciUnavailableException) {
            // #687 — le compte est déjà authentifié en local : une PANNE (réponse indéterminée)
            // garde le repli local.
            $payload = null;
        } catch (KlassciCompteRefuse $refus) {
            // #1739 — un REFUS explicite du compte est une réponse conclusive : KLASSCI fait
            // autorité pour son école, le compte local lié ne passe pas outre.
            return $this->compteRefuse($refus, $user);
        }
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
     * #1739 — le refus du compte par KLASSCI : 403 motivé, avec son message nettoyé. Journalisé
     * comme un échec de connexion, motivé : l'action reste celle que filtre le journal d'audit.
     */
    private function compteRefuse(KlassciCompteRefuse $refus, ?User $compte, ?string $username = null): JsonResponse
    {
        $contexte = ['reason' => KlassciCompteRefuse::REASON] + ($username !== null ? ['username' => $username] : []);
        $this->auditLogger->logAuthEvent('login_failed', $compte, $contexte);

        return $this->presenter->compteRefuse($refus->messageAffichable);
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
            $this->capacites->de($institution),
            $this->idDansLAnnuaire->de($localUser),
        );
    }
}
