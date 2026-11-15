<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Un échec de la re-synchronisation passive (`auth/me`) ne laissait aucune
 * trace : la requête suivante du compte relançait l'appel. Un 401 (jeton KLASSCI
 * révoqué ou expiré) ou un 429 n'est ni mis en cache ni compté par le
 * disjoncteur : sans recul, un compte dans ce cas rappelait KLASSCI à CHAQUE
 * requête `klassci.sync`. Le garde qui décide de la relance retient désormais
 * l'échec, compte par compte, pour une durée bornée.
 *
 * Seule la frontière réseau est feinte (une closure par requête, routée par le
 * jeton porteur) ; le proxy, son cache, le disjoncteur et le middleware sont
 * réels, derrière un vrai jeton Sanctum.
 *
 * L'expiration est simulée par `travel()` : sous la jambe CI redis, le store expire
 * sur l'horloge réelle. Les cas qui en dépendent (la fin du recul, et la réponse
 * illisible, gardée 300 s en cache) y sont sautés ; les jambes `database` les
 * portent (précédent : EchecKlassciEnCacheTest, SearchDegradationTest).
 */
final class ReculDeLaResynchronisationTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant.test';

    /** Une route du groupe `klassci.sync` qui n'appelle pas KLASSCI elle-même. */
    private const ROUTE_SYNCHRONISEE = '/api/chapters/999999';

    /** @var array<string, int> appels `auth/me` reçus, par jeton KLASSCI */
    private array $appels = [];

    /** @var array<string, \Closure(): PromiseInterface> réponse de `auth/me`, par jeton KLASSCI */
    private array $reponses = [];

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ecole = Institution::factory()->create(['klassci_api_url' => self::TENANT_URL]);
        $this->feindreKlassci();
    }

    /**
     * @param  \Closure(): PromiseInterface  $echec
     */
    #[DataProvider('echecs')]
    public function test_un_echec_retient_la_relance_du_compte(\Closure $echec, bool $misEnCache): void
    {
        if ($misEnCache) {
            $this->sauterSiLeStoreSuitLHorlogeReelle();
        }
        $compte = $this->comptePerime('jeton-a');
        $this->reponses['jeton-a'] = $echec;

        // Au-delà du cache par jeton (300 s), en deçà du recul : seul le recul évite l'appel.
        foreach ([0, 6, 6] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->requeteDe($compte);
        }

        self::assertSame(1, $this->appels['jeton-a'] ?? 0, 'Après un échec, le compte ne rappelle pas auth/me avant la fin du recul.');
        self::assertFalse($compte->refresh()->isKlassciDataFresh());
    }

    /** @return array<string, array{0: \Closure(): PromiseInterface, 1: bool}> */
    public static function echecs(): array
    {
        return [
            'jeton révoqué (401)' => [fn () => Http::response(['message' => 'Unauthenticated.'], 401), false],
            'filtre de volume (429)' => [fn () => Http::response(['message' => 'Too Many Attempts.'], 429), false],
            'panne (503)' => [fn () => Http::response(['message' => 'Service Unavailable'], 503), false],
            // La forme de auth/login, jamais celle de auth/me : illisible (#1087), gardée 300 s en cache.
            'réponse illisible' => [fn () => Http::response(['success' => true, 'data' => ['user' => ['id' => 1]]]), true],
        ];
    }

    public function test_a_la_fin_du_recul_une_nouvelle_tentative_date_le_compte(): void
    {
        $this->sauterSiLeStoreSuitLHorlogeReelle();
        $compte = $this->comptePerime('jeton-a');
        $this->reponses['jeton-a'] = fn () => Http::response(['message' => 'Unauthenticated.'], 401);
        $this->requeteDe($compte);

        $this->travel(61)->minutes();
        $this->reponses['jeton-a'] = fn () => $this->formeReelle($compte);
        $this->requeteDe($compte);

        self::assertSame(2, $this->appels['jeton-a'] ?? 0);
        self::assertTrue($compte->refresh()->isKlassciDataFresh());
    }

    public function test_le_recul_d_un_compte_ne_retient_pas_un_autre_compte_de_l_ecole(): void
    {
        $enEchec = $this->comptePerime('jeton-a');
        $voisin = $this->comptePerime('jeton-b');
        $this->reponses['jeton-a'] = fn () => Http::response(['message' => 'Unauthenticated.'], 401);
        $this->reponses['jeton-b'] = fn () => $this->formeReelle($voisin);

        $this->requeteDe($enEchec);
        $this->requeteDe($voisin);

        self::assertSame(1, $this->appels['jeton-b'] ?? 0);
        self::assertTrue($voisin->refresh()->isKlassciDataFresh(), 'Le compte voisin se re-synchronise malgré le recul du premier.');
    }

    public function test_un_succes_n_ouvre_aucun_recul(): void
    {
        $compte = $this->comptePerime('jeton-a');
        $this->reponses['jeton-a'] = fn () => $this->formeReelle($compte);
        $this->requeteDe($compte);

        // Périmé de nouveau, sans attendre : seul un recul ouvert à tort empêcherait de
        // le re-dater. La réponse peut venir du cache (redis) : la date seule fait foi.
        // Relu d'abord : l'instance garde la date de sa création, et la même date
        // remise à l'identique ne serait pas écrite.
        $compte->refresh()->forceFill(['last_klassci_sync' => now()->subHours(25)])->save();
        self::assertFalse($compte->refresh()->isKlassciDataFresh());
        $this->requeteDe($compte);

        self::assertTrue($compte->refresh()->isKlassciDataFresh(), 'Un succès ne retient pas la relance suivante.');
    }

    private function sauterSiLeStoreSuitLHorlogeReelle(): void
    {
        if (app('cache')->store()->getStore() instanceof RedisStore) {
            self::markTestSkipped('TTL redis suit l horloge réelle, pas le time-travel Carbon.');
        }
    }

    private function comptePerime(string $jeton): User
    {
        return User::factory()->for($this->ecole)->create([
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'klassci_token' => $jeton,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now()->subHours(25),
        ]);
    }

    private function requeteDe(User $compte): void
    {
        // Chaque requête est un cycle neuf : seuls le cache et la base survivent.
        $this->app->forgetScopedInstances();
        $this->asTenant($compte)->getJson(self::ROUTE_SYNCHRONISEE);
    }

    private function formeReelle(User $compte): PromiseInterface
    {
        return Http::response([
            'success' => true,
            'data' => ['id' => $compte->klassci_id, 'nom' => 'Nom KLASSCI', 'role' => 'enseignant'],
        ]);
    }

    /**
     * Feinte de la seule frontière réseau, une closure par requête : `auth/me`
     * est compté et routé par le jeton porteur ; tout autre chemin rend une liste vide.
     */
    private function feindreKlassci(): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) {
            if (! str_contains($requete->url(), 'auth/me')) {
                return Http::response(['success' => true, 'data' => []]);
            }
            $jeton = str_replace('Bearer ', '', $requete->header('Authorization')[0] ?? '');
            $this->appels[$jeton] = ($this->appels[$jeton] ?? 0) + 1;

            return ($this->reponses[$jeton])();
        });

        $this->app->instance(HttpFactory::class, $factory);
    }
}
