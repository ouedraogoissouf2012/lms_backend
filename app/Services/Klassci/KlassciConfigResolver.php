<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Psr\Log\LoggerInterface;

/**
 * PERF-02 (issue #137) — Résolution multi-tenant de la config KLASSCI (URL + token).
 *
 * ## Priorité 3-tiers (extraite verbatim de l'ancienne `KlassciProxyService::resolveConfig()`)
 *
 *   1. **Token personnel** de l'utilisateur connecté (KLASSCI login) — `users.klassci_token`,
 *      seulement vers SA cible : l'adresse du compte, si c'est celle de son école active et
 *      non autonome ({@see self::cibleDuJetonPersonnel()}, #1248). Sinon le refus est
 *      ABSORBANT : `null`, jamais une retombée en 2 ou 3 qui porterait ce jeton ailleurs.
 *   2. **Token système** de l'institution matchant `klassci_tenant_url` de l'user
 *      (pour les comptes créés via auth locale sans token personnel).
 *   3. **Configuration du tenant résolu** — ou config système globale lorsqu'il
 *      n'y a AUCUN tenant (supradmin, routes publiques sans user).
 *
 * ## Le `null` d'un tenant résolu est absorbant (#792)
 *
 * En priorité 3, aucun repli sur `config('services.klassci.*')` : c'est
 * {@see TenantManager::klassciConfig()} qui porte la distinction
 * entre « aucun tenant » et « tenant résolu ». Un `??` à cet endroit confondait
 * les deux, et une école autonome héritait alors de la cible — et du jeton — du
 * serveur. Cette ligne disait encore « token système global » après le
 * correctif : l'en-tête d'un point de liaison ne doit pas décrire un
 * comportement que le corps a cessé d'avoir.
 *
 * ## Sécurité — issue #75 cross-tenant fix préservé
 *
 * Priorité 2 utilise la relation directe `user->institution` (FK `institution_id`)
 * et JAMAIS un lookup par `klassci_api_url`. Deux institutions peuvent partager le
 * même serveur KLASSCI (même URL) ; un lookup par URL est ambigu et exposait à une
 * fuite cross-institution (analog #23).
 *
 * Check défensif : si l'institution résolue n'a pas la même URL que celle enregistrée
 * chez l'user, on REFUSE de monter un token cross-tenant et on laisse Priority 3
 * prendre le relais (fail-secure).
 *
 * ## Lazy + memoized par instance
 *
 * Résolu au 1er appel à {@see self::resolve()}, mémorisé pour la durée de vie de
 * l'instance (singleton implicite par requête HTTP). Pas de re-résolution même si
 * l'utilisateur authentifié change — comportement aligné avec l'ancien code.
 *
 * ## #578 — source de partition du circuit breaker
 *
 * Implémente {@see KlassciTargetResolver} : `baseUrl()` est la cible réseau que
 * {@see KlassciCircuitBreaker} utilise pour cloisonner son état par serveur. La
 * signature est déjà celle du contrat — aucun autre changement requis ici.
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §2
 * @see .claude/specs/578-circuit-breaker-per-tenant/design.md §2.1
 */
final class KlassciConfigResolver implements KlassciTargetResolver
{
    private ?string $baseUrl = null;

    private ?string $token = null;

    private bool $resolved = false;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Oublie la résolution mémoïsée, pour que le prochain appel reparte à zéro.
     *
     * La resolution est lazy et mise en cache sur la duree de l'instance, qui est
     * `scoped` : une par requete HTTP. En requete, le contexte ne change jamais,
     * donc rien a oublier. Un JOB, lui, traverse plusieurs tenants dans la meme
     * instance : sans cet oubli, l'URL et le token du premier tenant resteraient
     * figes et le trafic destine au second partirait vers le premier.
     *
     * A appeler apres tout changement de tenant, jamais en cours de requete HTTP
     * (ou un changement de contexte serait deja une faille, cf. TenantManager::reset).
     */
    public function forget(): void
    {
        $this->resolved = false;
        $this->baseUrl = null;
        $this->token = null;
    }

    /**
     * Retourne la baseUrl résolue (toutes priorités confondues).
     */
    public function baseUrl(): ?string
    {
        $this->resolve();

        return $this->baseUrl;
    }

    /**
     * Retourne le token système résolu (toutes priorités confondues).
     * Peut être null si aucune source ne fournit de token (cas dégradé).
     */
    public function token(): ?string
    {
        $this->resolve();

        return $this->token;
    }

    /**
     * Retourne la baseUrl résolue en GARANTISSANT qu'elle est exploitable pour un
     * appel HTTP : non vide, scheme http/https, host présent.
     *
     * #270 — À utiliser AVANT toute requête KLASSCI (au lieu de {@see self::baseUrl()}
     * qui, lui, tolère null pour les appelants qui n'émettent pas de requête). Si
     * l'URL est absente/invalide (`KLASSCI_API_URL` non configurée), construire une
     * URL produirait un scheme vide (`"/endpoint"`) et Guzzle lèverait un
     * `InvalidArgumentException` rendu en 500 trompeur. On lève plutôt
     * {@see KlassciUnavailableException} → 503 (panne/mauvaise config externe).
     *
     * @throws KlassciUnavailableException si l'URL de base est absente ou invalide.
     */
    public function requireBaseUrl(): string
    {
        $this->resolve();

        if (! is_string($this->baseUrl) || ! self::isUsableHttpUrl($this->baseUrl)) {
            // Warning (pas error) : situation exceptionnelle de config, pas une
            // panne applicative. Catégorie seulement — on ne logge jamais la valeur
            // brute (un token pourrait s'y trouver) ni le body (#256).
            $this->logger->warning('KLASSCI base URL absente ou invalide — requête proxy dégradée en 503 (#270).', [
                'reason' => $this->baseUrl === null ? 'missing' : 'invalid_scheme_or_host',
            ]);

            throw KlassciUnavailableException::missingBaseUrl();
        }

        return $this->baseUrl;
    }

    /**
     * #1248 — « cette école parle-t-elle à KLASSCI ? » : active, pas autonome, et d'URL
     * exploitable. Sinon `null` : une école détachée (basculée en autonome, ou URL vidée)
     * n'a plus de cible.
     */
    public function cibleDeLEcole(?Institution $ecole): ?string
    {
        $url = $ecole?->klassci_api_url;

        return $ecole instanceof Institution && $ecole->is_active
            && $ecole->mode !== InstitutionMode::Standalone
            && is_string($url) && self::isUsableHttpUrl($url) ? $url : null;
    }

    /**
     * #1248 — l'adresse du compte, si et seulement si c'est la cible de SON école. Un jeton
     * personnel ne part jamais vers une autre adresse que celle qui l'a émis.
     */
    public function cibleDuJetonPersonnel(User $compte): ?string
    {
        $adresse = self::adresseDuCompte($compte);

        return $adresse !== null && $adresse === $this->cibleDeLEcole($compte->institution) ? $adresse : null;
    }

    /**
     * #1248 — où la re-synchronisation de ce compte enverrait `auth/me`, ou `null` si nulle
     * part : SA cible s'il porte une adresse, sinon celle de son école rattachée. Une école
     * détachée ou inactive n'a plus de re-synchronisation, avec ou sans adresse.
     */
    public function cibleDeSynchronisation(User $compte): ?string
    {
        return self::adresseDuCompte($compte) !== null
            ? $this->cibleDuJetonPersonnel($compte)
            : $this->cibleDeLEcole($compte->institution);
    }

    /** `klassci_tenant_url`, ou le repli `_lms_tenant_url` des comptes d'avant #75. */
    private static function adresseDuCompte(User $compte): ?string
    {
        $adresse = $compte->klassci_tenant_url ?: ($compte->klassci_data['_lms_tenant_url'] ?? null);

        return is_string($adresse) && $adresse !== '' ? $adresse : null;
    }

    /**
     * Une URL est exploitable ssi elle porte un scheme http(s) ET un host non vide.
     * `parse_url` isole la cause historique du 500 (#270) : une valeur sans scheme.
     */
    private static function isUsableHttpUrl(string $url): bool
    {
        if (trim($url) === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }

    /**
     * Résolution lazy 3-tiers — appelée idempotente sur la durée d'instance.
     */
    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $user = $this->auth->guard('sanctum')->user();

        // Priorité 1 : token personnel de l'utilisateur connecté, vers SA cible seulement.
        if ($user instanceof User && $user->klassci_token && $this->cibleDuJetonPersonnel($user) !== null) {
            // #1248 — le refus est ABSORBANT : le jeton du compte ne retombe pas en
            // priorité 2 ou 3, qui le porteraient vers une autre adresse que la sienne.
            $this->baseUrl = $this->cibleDuJetonPersonnel($user);
            $this->token = $this->baseUrl === null ? null : $user->klassci_token;
            $this->resolved = true;

            if ($this->baseUrl === null) {
                $this->logger->warning('KLASSCI resolveConfig: école détachée, jeton personnel retenu (#1248)', [
                    'user_id' => $user->id,
                    'institution_id' => $user->institution_id,
                ]);
            } elseif (! $user->klassci_tenant_url) {
                // Migration silencieuse : remplir klassci_tenant_url si manquant.
                $user->updateQuietly(['klassci_tenant_url' => $this->baseUrl]);
            }

            return;
        }

        // Priorité 2 : token système institution (compte manuel sans token personnel) —
        // ATTENTION sécurité issue #75 : lookup par FK user->institution, jamais par URL.
        if ($user instanceof User && is_string($user->klassci_tenant_url) && $user->klassci_tenant_url !== '') {
            $institution = $user->institution;

            if ($institution instanceof Institution
                && $institution->is_active
                && $institution->klassci_api_url === $user->klassci_tenant_url
                && $institution->klassci_api_token) {
                $this->baseUrl = $institution->klassci_api_url;
                $this->token = $institution->klassci_api_token;
                $this->resolved = true;

                $this->logger->info('KLASSCI resolveConfig: using institution token (no personal token)', [
                    'user_id' => $user->id,
                    'institution' => $institution->slug,
                ]);

                return;
            }
        }

        // Priorité 3 : configuration du tenant résolu, ou config système globale
        // quand il n'y en a aucun (supradmin, routes publiques).
        //
        // AUCUN repli ici, et c'est le correctif de #792. `klassciConfig()` porte
        // DÉJÀ la distinction : il rend la config globale quand aucune institution
        // n'est résolue, et celle du tenant sinon. Un `?? config(…)` à cet endroit
        // était donc redondant sur la première branche et nuisible sur la seconde :
        // il confondait « aucun tenant » avec « tenant résolu, explicitement sans
        // KLASSCI », si bien qu'une école autonome héritait de la cible du serveur.
        //
        // La suppression vaut pour l'URL ET pour le jeton. Un tenant qui déclare
        // une URL sans jeton — `klassci_api_token` est `nullable` à l'écran —
        // empruntait le jeton système du serveur pour parler à SA cible : c'est
        // envoyer les identifiants du serveur à un hôte tiers, la fuite que la
        // priorité 2 refuse déjà par ailleurs (#75).
        //
        // Le `null` d'un tenant résolu est donc absorbant : un chemin qui exige
        // quand même KLASSCI échoue bruyamment via `requireBaseUrl()`, ce qui vaut
        // mieux que réussir sur la cible — ou avec les identifiants — d'un tiers.
        $config = $this->tenantManager->klassciConfig();

        $this->baseUrl = is_string($config['url']) ? $config['url'] : null;
        $this->token = is_string($config['token']) ? $config['token'] : null;
        $this->resolved = true;
    }
}
