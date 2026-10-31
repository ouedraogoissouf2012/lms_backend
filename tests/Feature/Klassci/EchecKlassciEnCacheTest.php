<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Un échec de KLASSCI n'est pas gardé en cache comme une donnée.
 *
 * `GET /lms/classes/{id}/etudiants` rend 503 avec `Retry-After: 30` quand
 * KLASSCI répond 200 sans roster (#987). Mais la réponse fautive restait dans le
 * cache KLASSCI 300 s : chaque nouvel essai relisait le même échec, cinq minutes
 * durant. Désormais, un échec que KLASSCI déclare (`success: false`) n'est gardé
 * que le délai annoncé, et un corps illisible n'est pas gardé du tout. Une
 * réponse valide reste servie par le cache. Même règle pour les lots
 * (`fetchMany*`).
 *
 * Entre deux requêtes, le test fait ce que fait la production : une requête
 * neuve n'hérite ni de la mémo intra-requête (`forgetScopedInstances()`), ni du
 * contrôleur mémoïsé sur la route (`flushController()`). Sans eux, un seul
 * appel en deux requêtes prouverait la mémo, pas le cache.
 *
 * L'expiration est simulée par `travel()` : sous la jambe CI redis, le store expire
 * sur l'horloge réelle, ces deux cas sont donc sautés (précédent SearchDegradationTest).
 * Le TTL transmis au store étiqueté est prouvé par TenantScopedCacheTest.
 *
 * Volet KLASSCI seul : un compte d'école autonome n'a pas de jeton KLASSCI, et
 * ces lectures lui rendent le 401 motivé (couvert par ClasseEtudiantsTest).
 * Une seule closure `Http::fake` par test (deux faux s'empilent, le premier
 * gagne). Jeton porteur réel (`asTenant`).
 *
 * @see app/Services/Klassci/KlassciCacheTtl.php
 */
final class EchecKlassciEnCacheTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const ROUTE = '/api/lms/classes/42/etudiants';

    private const ECHEC = ['success' => false, 'message' => 'Classe indisponible'];

    private const ENVELOPPE = ['success' => true, 'data' => [
        'classe' => ['id' => 42],
        'etudiants' => [['id' => 1, 'nom_complet' => 'DOE Ana', 'statut' => 'actif']],
    ]];

    private Institution $ecole;

    private User $enseignant;

    /** @var list<string> */
    private array $appelsKlassci = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->ecole = Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]);
        $this->enseignant = User::factory()->teacher()->for($this->ecole)->create([
            'klassci_token' => 'jeton-enseignant',
            'klassci_tenant_url' => self::BASE_URL,
        ]);
    }

    /**
     * L'échec déclaré est relu PENDANT le délai annoncé (la charge reste bornée),
     * puis KLASSCI est réinterrogé : le client qui revient après `Retry-After`
     * voit la classe. Avant : le même 503 jusqu'à 300 s.
     */
    public function test_un_echec_declare_n_est_garde_que_le_delai_annonce(): void
    {
        $this->sauterSiLeStoreSuitLHorlogeReelle();
        $this->klassciRepond([self::ECHEC, self::ENVELOPPE]);

        $this->lireLesEleves()->assertStatus(503)->assertHeader('Retry-After');
        $this->lireLesEleves()->assertStatus(503);
        self::assertCount(1, $this->appelsKlassci, 'pendant le délai annoncé, l échec est relu du cache');

        $this->travel(KlassciUnavailableException::retryAfterSeconds() + 1)->seconds();

        $this->lireLesEleves()->assertOk()->assertJsonPath('data.total', 1);
        self::assertCount(2, $this->appelsKlassci, 'après le délai annoncé, KLASSCI est réinterrogé');
    }

    public function test_une_enveloppe_valide_reste_servie_par_le_cache(): void
    {
        $this->klassciRepond([self::ENVELOPPE]);

        $this->lireLesEleves()->assertOk()->assertJsonPath('data.total', 1);
        $this->travel(KlassciUnavailableException::retryAfterSeconds() + 1)->seconds();
        $this->lireLesEleves()->assertOk()->assertJsonPath('data.total', 1);

        self::assertCount(1, $this->appelsKlassci, 'une donnée garde sa durée de cache');
    }

    /** Un 200 au corps illisible n'est ni une classe vide, ni une donnée à garder. */
    public function test_un_corps_illisible_n_est_pas_garde(): void
    {
        $this->klassciRepond(['<html>maintenance</html>', self::ENVELOPPE]);

        $this->lireLesEleves()->assertStatus(503)->assertHeader('Retry-After');
        $this->lireLesEleves()->assertOk()->assertJsonPath('data.total', 1);

        self::assertCount(2, $this->appelsKlassci, 'le corps illisible n a pas été mis en cache');
    }

    /** Les lots (`fetchMany*`, Http::pool) suivent la même règle. */
    public function test_un_lot_ne_garde_un_echec_declare_que_le_delai_annonce(): void
    {
        $this->sauterSiLeStoreSuitLHorlogeReelle();
        $this->klassciRepond([self::ECHEC, self::ENVELOPPE]);

        self::assertSame([42 => self::ECHEC], $this->lireLeLot());

        $this->travel(KlassciUnavailableException::retryAfterSeconds() + 1)->seconds();

        self::assertSame([42 => self::ENVELOPPE], $this->lireLeLot());
        self::assertCount(2, $this->appelsKlassci);
    }

    public function test_un_lot_ne_rend_ni_ne_garde_un_corps_illisible(): void
    {
        $this->klassciRepond(['<html>maintenance</html>', self::ENVELOPPE]);

        self::assertSame([], $this->lireLeLot(), 'un corps illisible n est pas une classe vide');
        self::assertSame([42 => self::ENVELOPPE], $this->lireLeLot());
        self::assertCount(2, $this->appelsKlassci);
    }

    private function sauterSiLeStoreSuitLHorlogeReelle(): void
    {
        if (app('cache')->store()->getStore() instanceof RedisStore) {
            self::markTestSkipped('TTL redis suit l horloge réelle, pas le time-travel Carbon.');
        }
    }

    /**
     * Une seule closure : chaque appel à `classes/42` consomme la réponse
     * suivante, la dernière se répète. Une chaîne est rendue telle quelle.
     *
     * @param  non-empty-list<array<string, mixed>|string>  $reponses
     */
    private function klassciRepond(array $reponses): void
    {
        Http::fake(function (Request $requete) use ($reponses) {
            if (! str_contains($requete->url(), '/classes/42')) {
                return Http::response(['success' => true, 'data' => []]);
            }

            $corps = $reponses[min(count($this->appelsKlassci), count($reponses) - 1)];
            $this->appelsKlassci[] = $requete->url();

            return Http::response($corps, 200, is_string($corps) ? ['Content-Type' => 'text/html'] : []);
        });
    }

    private function lireLesEleves(): TestResponse
    {
        // Une requête neuve, comme en production : la mémo intra-requête ne
        // survit pas, ni le contrôleur que Laravel mémoïse sur la route (et,
        // avec lui, le proxy qui tient la mémo). Seul Octane le vide d'office.
        $this->app->forgetScopedInstances();
        $this->app['router']->getRoutes()->getByName('lms.classes.etudiants')?->flushController();

        return $this->asTenant($this->enseignant)->getJson(self::ROUTE);
    }

    /** @return array<int, array<string, mixed>> */
    private function lireLeLot(): array
    {
        $this->app->forgetScopedInstances();
        app(TenantManager::class)->set($this->ecole);

        return app(KlassciProxyService::class)->fetchManyClassesDetails([42], 'jeton-enseignant');
    }
}
