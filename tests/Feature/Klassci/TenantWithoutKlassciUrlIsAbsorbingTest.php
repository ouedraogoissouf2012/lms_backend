<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #792 — le `null` d'un tenant résolu est absorbant.
 *
 * ## Le défaut
 *
 * `TenantManager::klassciConfig()` rend déjà la bonne chose : la configuration
 * du tenant quand il est résolu — `['url' => null, …]` pour une école autonome
 * — et la configuration globale seulement quand il n'y en a aucun.
 *
 * Mais `KlassciConfigResolver` écrivait `$config['url'] ?? config('services.
 * klassci.url')`, ce qui CONFONDAIT « aucun tenant » et « tenant résolu,
 * explicitement sans KLASSCI ». Une école autonome héritait donc de la cible
 * du serveur et y lisait — et écrivait — avec le jeton système. Les seeders
 * pointent `presentation.klassci.com`.
 *
 * ## Ce que ces tests garantissent
 *
 * L'environnement porte ici une URL KLASSCI globale VALIDE, contrairement au
 * runner par défaut : c'est la seule façon de prouver que le repli ne se
 * produit plus. Un test qui s'appuie sur une configuration absente mesure le
 * runner, pas la garde.
 *
 * @see docs/adr/2026-09-17-792-01-null-absorbant-du-tenant.md
 */
final class TenantWithoutKlassciUrlIsAbsorbingTest extends TestCase
{
    use RefreshDatabase;

    /** Cible globale du serveur : celle qu'une école autonome ne doit JAMAIS emprunter. */
    private const CIBLE_GLOBALE = 'https://presentation.klassci.test';

    protected function setUp(): void
    {
        parent::setUp();

        // Le défaut n'est visible QUE si l'environnement serveur est renseigné.
        Config::set('services.klassci.url', self::CIBLE_GLOBALE);
        Config::set('services.klassci.token', 'jeton-systeme-global');
    }

    private function resolveurPourTenant(?Institution $institution): KlassciConfigResolver
    {
        $tenants = app(TenantManager::class);

        if ($institution instanceof Institution) {
            $tenants->set($institution);
        } else {
            $tenants->reset();
        }

        return app(KlassciConfigResolver::class);
    }

    public function test_une_ecole_autonome_n_herite_pas_de_la_cible_du_serveur(): void
    {
        $autonome = Institution::factory()->create([
            'klassci_api_url' => null,
            'klassci_api_token_encrypted' => null,
        ]);

        $resolveur = $this->resolveurPourTenant($autonome);

        self::assertNull(
            $resolveur->baseUrl(),
            'Une école autonome a emprunté la cible KLASSCI du serveur.',
        );
    }

    public function test_une_ecole_autonome_n_emet_aucune_requete_sortante(): void
    {
        // La preuve demandée par #792 : zéro appel réseau. Sans elle, on ne
        // saurait pas si la cible est absente ou seulement inutilisée.
        Http::fake();

        $autonome = Institution::factory()->create([
            'klassci_api_url' => null,
            'klassci_api_token_encrypted' => null,
        ]);

        $resolveur = $this->resolveurPourTenant($autonome);

        try {
            $resolveur->requireBaseUrl();
            self::fail('Un chemin exigeant KLASSCI aurait dû échouer bruyamment.');
        } catch (KlassciUnavailableException) {
            // Échouer bruyamment bat réussir sur la mauvaise cible.
        }

        Http::assertNothingSent();
    }

    public function test_une_ecole_autonome_n_emprunte_pas_le_jeton_du_serveur(): void
    {
        // Trou trouvé en relisant : la suppression vaut pour l'URL ET pour le
        // jeton, et seuls les cas d'URL étaient couverts.
        $autonome = Institution::factory()->create([
            'klassci_api_url' => null,
            'klassci_api_token_encrypted' => null,
        ]);

        self::assertNull(
            $this->resolveurPourTenant($autonome)->token(),
            'Une école autonome a emprunté le jeton système du serveur.',
        );
    }

    public function test_une_ecole_avec_une_url_mais_sans_jeton_n_emprunte_pas_celui_du_serveur(): void
    {
        // Le cas le plus insidieux, et il est atteignable depuis l'écran :
        // `klassci_api_token` y est `nullable`. Une telle école empruntait le
        // jeton système pour parler à SA cible — c'est envoyer les identifiants
        // du serveur à un hôte tiers, exactement la fuite que la priorité 2
        // refuse par ailleurs (#75).
        $sansJeton = Institution::factory()->create([
            'klassci_api_url' => 'https://tiers.klassci.test',
            'klassci_api_token_encrypted' => null,
        ]);

        $resolveur = $this->resolveurPourTenant($sansJeton);

        self::assertSame('https://tiers.klassci.test', $resolveur->baseUrl(), 'Sa cible propre doit rester.');
        self::assertNull($resolveur->token(), 'Le jeton du serveur a fui vers un hôte tiers.');
    }

    public function test_une_ecole_liee_garde_sa_propre_cible(): void
    {
        // Non-régression : le correctif ne doit pas priver de cible celles qui
        // en ont une. C'est le chemin que les écoles KLASSCI exécutent en
        // production.
        $liee = Institution::factory()->create([
            'klassci_api_url' => 'https://ecole-liee.klassci.test',
        ]);

        self::assertSame(
            'https://ecole-liee.klassci.test',
            $this->resolveurPourTenant($liee)->baseUrl(),
        );
    }

    public function test_sans_tenant_resolu_la_config_globale_s_applique_toujours(): void
    {
        // Le supradmin et les routes publiques s'exécutent hors tenant : leur
        // chemin doit rester intact. C'est `TenantManager` qui porte ce repli,
        // et lui seul a le droit de le décider.
        self::assertSame(
            self::CIBLE_GLOBALE,
            $this->resolveurPourTenant(null)->baseUrl(),
        );
    }
}
