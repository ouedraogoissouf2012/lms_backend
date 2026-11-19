<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\Klassci\Sync\KlassciSyncGate;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1248 — une école détachée de KLASSCI ne lui parle plus par les comptes de ses
 * membres. Détachée veut dire : basculée en autonome, URL vidée (#794), URL changée
 * (l'adresse qui a émis le jeton n'est plus la sienne), ou école inactive.
 *
 * La règle est UNE définition du résolveur (`cibleDuJetonPersonnel`), lue par la
 * priorité 1 et par la garde de re-synchronisation. Son refus est ABSORBANT : le jeton
 * du compte ne retombe pas en priorité 2 ou 3, qui le porteraient vers une autre
 * adresse que celle qui l'a émis (cas « autonome d'URL Y, compte émis par X »).
 *
 * Seule la frontière réseau est feinte, une closure par requête, qui relève CHAQUE
 * requête émise ; le middleware, le proxy et le résolveur sont réels, derrière un vrai
 * jeton porteur.
 */
final class EcoleDetacheeNeParlePlusAKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ADRESSE_DU_COMPTE = 'https://klassci-x.tenant.test';

    private const AUTRE_ADRESSE = 'https://klassci-y.tenant.test';

    private const JETON_DU_COMPTE = 'jeton-personnel-du-compte';

    /** Une route du groupe `klassci.sync` qui n'appelle pas KLASSCI elle-même. */
    private const ROUTE_SYNCHRONISEE = '/api/chapters/999999';

    /** @var list<array{url: string, autorisation: string}> */
    private array $envoyees = [];

    private ?int $klassciIdDuCompte = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reseauRelevePuisRepond();
    }

    public function test_une_ecole_klassci_rattachee_resynchronise_son_compte(): void
    {
        $compte = $this->comptePerimeDans($this->ecole());

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([['url' => self::ADRESSE_DU_COMPTE.'/auth/me', 'autorisation' => 'Bearer '.self::JETON_DU_COMPTE]], $this->envoyees);
        self::assertSame('Nom KLASSCI', $compte->refresh()->name);
    }

    /**
     * Les 5 comptes réels (mesurés en prod) : école KLASSCI active, adresse du compte =
     * URL de l'école. Rien ne change : la priorité 1 rend cette adresse et le jeton du compte.
     */
    public function test_un_compte_rattache_garde_sa_cible_et_son_jeton(): void
    {
        $ecole = $this->ecole();
        $this->connecteSansRequete($this->comptePerimeDans($ecole), $ecole);

        $resolveur = app(KlassciConfigResolver::class);

        self::assertSame(self::ADRESSE_DU_COMPTE, $resolveur->baseUrl());
        self::assertSame(self::JETON_DU_COMPTE, $resolveur->token());
    }

    /** @param  array<string, mixed>  $detachement */
    #[DataProvider('detachements')]
    public function test_une_ecole_detachee_n_appelle_plus_auth_me(array $detachement): void
    {
        $compte = $this->comptePerimeDans($this->ecole($detachement));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([], $this->envoyees);
        $compte->refresh();
        self::assertSame('Nom Avant', $compte->name);
        self::assertSame('Nom Avant', $compte->klassci_data['nom'] ?? null);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function detachements(): array
    {
        return [
            'basculée en autonome, URL gardée' => [['mode' => InstitutionMode::Standalone]],
            'URL vidée (#794)' => [['klassci_api_url' => null]],
            'URL changée' => [['klassci_api_url' => self::AUTRE_ADRESSE]],
        ];
    }

    /**
     * Un compte à jeton SANS adresse dans une école rattachée : sa re-synchronisation part
     * vers l'URL de l'école, comme aujourd'hui. C'est légitime, et rien ne la bloque.
     */
    public function test_un_compte_sans_adresse_d_une_ecole_rattachee_se_resynchronise(): void
    {
        $compte = $this->compteSansAdresseDans($this->ecole());

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([['url' => self::ADRESSE_DU_COMPTE.'/auth/me', 'autorisation' => 'Bearer '.self::JETON_DU_COMPTE]], $this->envoyees);
    }

    /** Le même compte sans adresse, école détachée : plus aucun auth/me (fait B, côté synchronisation). */
    public function test_un_compte_sans_adresse_d_une_ecole_detachee_n_appelle_plus_auth_me(): void
    {
        $compte = $this->compteSansAdresseDans($this->ecole(['mode' => InstitutionMode::Standalone]));

        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);

        self::assertSame([], $this->envoyees);
    }

    /** Une école inactive refuse déjà la requête : la garde est lue directement. */
    public function test_une_ecole_inactive_ne_resynchronise_pas(): void
    {
        $compte = $this->comptePerimeDans($this->ecole(['is_active' => false]));

        self::assertFalse(app(KlassciSyncGate::class)->shouldRefresh($compte));
        self::assertTrue(app(KlassciSyncGate::class)->shouldRefresh($this->comptePerimeDans($this->ecole())));
    }

    /**
     * Le fait B de l'ARRÊT : une école autonome d'URL Y, un compte émis par X. Refuser la
     * seule priorité 1 laisserait la priorité 3 rendre Y, et le jeton émis par X partirait
     * chez Y. Le refus absorbant ne rend AUCUNE cible : rien ne part, ni vers X ni vers Y.
     */
    public function test_le_refus_est_absorbant_le_jeton_ne_part_nulle_part(): void
    {
        $ecole = $this->ecole(['mode' => InstitutionMode::Standalone, 'klassci_api_url' => self::AUTRE_ADRESSE]);
        $compte = $this->comptePerimeDans($ecole);
        $this->connecteSansRequete($compte, $ecole);

        $resolveur = app(KlassciConfigResolver::class);
        self::assertNull($resolveur->baseUrl());
        self::assertNull($resolveur->token());

        try {
            app(KlassciProxyService::class)->requestWithUserToken(self::JETON_DU_COMPTE, 'auth/me');
        } catch (KlassciUnavailableException) {
            // Échec bruyant attendu : l'essentiel est qu'aucune requête ne soit partie.
        }

        self::assertSame([], $this->envoyees);
    }

    /** École KLASSCI d'URL changée : le jeton du compte ne part ni vers son ancienne adresse ni vers la nouvelle. */
    public function test_la_priorite_1_ne_retombe_pas_sur_le_jeton_de_l_ecole(): void
    {
        $ecole = $this->ecole(['klassci_api_url' => self::AUTRE_ADRESSE]);
        $compte = $this->comptePerimeDans($ecole);
        $this->connecteSansRequete($compte, $ecole);

        $resolveur = app(KlassciConfigResolver::class);

        self::assertNull($resolveur->baseUrl());
        self::assertNull($resolveur->token());
    }

    /**
     * Hors de #1248, déclaré dans la PR : un compte à jeton SANS adresse garde son chemin
     * (priorités 2 puis 3). La lecture du mode y est l'objet de #1249.
     */
    public function test_un_compte_sans_adresse_garde_son_chemin_de_resolution(): void
    {
        $ecole = $this->ecole();
        $compte = User::factory()->for($ecole)->create(['klassci_token' => self::JETON_DU_COMPTE, 'klassci_tenant_url' => null]);
        $this->connecteSansRequete($compte, $ecole);

        self::assertSame(self::ADRESSE_DU_COMPTE, app(KlassciConfigResolver::class)->baseUrl());
    }

    /** @param  array<string, mixed>  $attributs */
    private function ecole(array $attributs = []): Institution
    {
        return Institution::factory()->create($attributs + [
            'klassci_api_url' => self::ADRESSE_DU_COMPTE,
            'mode' => InstitutionMode::Klassci,
            'is_active' => true,
        ]);
    }

    /**
     * Le contexte d'une requête authentifiée, sans requête : école résolue (priorité 3)
     * et compte connecté (priorité 1), sur une résolution neuve.
     */
    private function connecteSansRequete(User $compte, Institution $ecole): void
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->set($ecole);
        $this->app['auth']->guard('sanctum')->setUser($compte);
    }

    /** Un compte à jeton sans adresse, ni `klassci_tenant_url` ni repli `_lms_tenant_url`. */
    private function compteSansAdresseDans(Institution $ecole): User
    {
        $compte = $this->comptePerimeDans($ecole);
        $compte->forceFill(['klassci_tenant_url' => null])->save();

        return $compte;
    }

    private function comptePerimeDans(Institution $ecole): User
    {
        $compte = User::factory()->for($ecole)->create([
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'name' => 'Nom Avant',
            'klassci_data' => ['nom' => 'Nom Avant'],
            'klassci_token' => self::JETON_DU_COMPTE,
            'klassci_tenant_url' => self::ADRESSE_DU_COMPTE,
            'last_klassci_sync' => now()->subHours(25),
        ]);
        $this->klassciIdDuCompte = $compte->klassci_id;

        return $compte;
    }

    /**
     * Feinte de la seule frontière réseau, une closure par requête : chaque requête est
     * relevée (adresse et jeton porteur) ; `auth/me` rend la forme réelle, le reste vide.
     */
    private function reseauRelevePuisRepond(): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) {
            $this->envoyees[] = ['url' => $requete->url(), 'autorisation' => $requete->header('Authorization')[0] ?? ''];
            if (! str_contains($requete->url(), 'auth/me')) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response(['success' => true, 'data' => ['id' => $this->klassciIdDuCompte, 'nom' => 'Nom KLASSCI', 'role' => 'enseignant']]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }
}
