<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseRoster;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #948 — une panne interne sous `renderKlassciFailure` est rapportée, une fois.
 *
 * La branche 500 du trait (`RendersKlassciBackedErrors`) rendait la réponse
 * générique sans rien écrire : l'exception est attrapée, le rapporteur de
 * Laravel ne la voyait jamais. Depuis #987, la route des élèves d'une classe
 * confie au trait toute `RuntimeException`, dont une `QueryException` : une
 * panne de base y était muette.
 *
 * Le rapport ne porte ni le jeton porteur, ni le jeton KLASSCI, ni la chaîne de
 * requête. Le jeton KLASSCI est un ARGUMENT de `ClasseRoster::etudiants()` : sans
 * `zend.exception_ignore_args`, la trace en écrirait les 15 premiers caractères.
 * Une `QueryException` est écrite sans les valeurs de sa requête : son message,
 * qui les porte, ne l'est pas.
 *
 * Les échecs KLASSCI (4xx relayé, indisponibilité) gardent leur rendu et ne sont
 * pas rapportés par le trait : le client HTTP les journalise déjà.
 *
 * École créée depuis KLASSCI : tous les cas. École créée depuis le LMS : un
 * compte sans jeton KLASSCI reçoit le 401 `klassci_token_missing` avant tout
 * appel du roster (`ClasseEtudiantsTest`), branche que ce lot ne touche pas.
 *
 * @see app/Http/Controllers/API/Concerns/RendersKlassciBackedErrors.php
 */
final class ClasseEtudiantsPanneInterneTest extends TestCase
{
    use ActsAsTenantUser;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const CLASSE_ID = 42;

    private const JETON_KLASSCI = 'temoin-klassci-948-jamais-ecrit';

    private const TEMOIN_REQUETE = 'temoin-requete-948-jamais-ecrit';

    /** Une adresse factice, liée à la requête SQL qui échoue. */
    private const TEMOIN_SQL = 'temoin.sql.948@exemple.invalid';

    private User $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
        $this->captureWrittenLog();

        $ecole = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'is_active' => true,
        ]);
        app(TenantManager::class)->set($ecole);

        $this->enseignant = User::factory()->teacher()->for($ecole)->create([
            'klassci_token' => self::JETON_KLASSCI,
            'klassci_tenant_url' => self::BASE_URL,
        ]);
    }

    /**
     * La classe levée, et les clés exactes de ce que son rapport écrit.
     *
     * @return iterable<string, array{0: class-string<RuntimeException>, 1: list<string>}>
     */
    public static function pannesInternes(): iterable
    {
        // Ni l'exception (son message porte les valeurs liées), ni le compte.
        yield 'panne de base (QueryException)' => [QueryException::class, [
            'code', 'connection', 'driver_code', 'exception_class', 'method', 'request_id', 'route', 'sql', 'trace',
        ]];
        // `userId` est ajouté par le rapport par défaut de Laravel (Handler::context()),
        // que suit toute panne hors SQL ; son retrait relève du lot Handler, déclaré
        // en #948.
        yield 'RuntimeException de code 0' => [RuntimeException::class, [
            'exception', 'method', 'request_id', 'route', 'userId',
        ]];
    }

    /**
     * @param  class-string<RuntimeException>  $panne
     * @param  list<string>  $cles
     */
    #[DataProvider('pannesInternes')]
    public function test_une_panne_interne_rend_le_500_et_est_rapportee_une_fois(string $panne, array $cles): void
    {
        $this->rosterEchoue($panne);
        $porteur = $this->porteur();

        $this->lire($porteur)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $enregistrements = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $enregistrements, 'La panne doit être écrite une fois.');
        $rapport = $enregistrements[0];
        self::assertSame(Level::Error, $rapport->level);
        $this->assertNoWitnessWritten(self::JETON_KLASSCI, $porteur, self::TEMOIN_REQUETE, self::TEMOIN_SQL);
        self::assertSame('lms.classes.etudiants', $rapport->context['route'] ?? null);
        self::assertSame('GET', $rapport->context['method'] ?? null);
        self::assertNotEmpty($rapport->context['request_id'] ?? null);
        $contexte = array_keys($rapport->context);
        sort($contexte);
        self::assertSame($cles, $contexte);
        if ($panne === QueryException::class) {
            self::assertSame(QueryException::class, $rapport->context['exception_class']);
            self::assertSame('select * from classes where email = ?', $rapport->context['sql']);
        } else {
            self::assertInstanceOf($panne, $rapport->context['exception']);
        }
    }

    /** @return iterable<string, array{0: int, 1: int}> */
    public static function echecsKlassci(): iterable
    {
        yield 'refus KLASSCI relayé (403)' => [403, 403];
        yield 'KLASSCI indisponible (503)' => [503, 503];
    }

    #[DataProvider('echecsKlassci')]
    public function test_un_echec_klassci_garde_son_rendu_et_n_est_pas_rapporte_par_le_trait(int $amont, int $rendu): void
    {
        Http::fake([
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'*' => Http::response(['success' => false], $amont),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
        $porteur = $this->porteur();

        $reponse = $this->lire($porteur)->assertStatus($rendu);

        if ($rendu === 503) {
            $reponse->assertHeader('Retry-After')
                ->assertJsonPath('message', KlassciUnavailableException::CLIENT_MESSAGE);
        } else {
            $reponse->assertJsonPath('message', 'Accès refusé par KLASSCI pour ce compte.');
        }
        self::assertSame([], array_values(array_filter(
            $this->recordsAtLeast(Level::Debug),
            static fn (LogRecord $enregistrement): bool => array_key_exists('exception', $enregistrement->context),
        )), 'Aucun rapport d\'exception : le client HTTP journalise déjà l\'échec KLASSCI.');
        $this->assertNoWitnessWritten(self::JETON_KLASSCI, $porteur, self::TEMOIN_REQUETE);
    }

    /**
     * Le double CONSTRUIT l'exception dans `etudiants()` : une trace est saisie à
     * la construction, et c'est cette frame qui porte le jeton KLASSCI en argument.
     *
     * @param  class-string<RuntimeException>  $panne
     */
    private function rosterEchoue(string $panne): void
    {
        $this->app->instance(ClasseRoster::class, new class($panne, self::TEMOIN_SQL) implements ClasseRoster
        {
            /** @param  class-string<RuntimeException>  $panne */
            public function __construct(private readonly string $panne, private readonly string $valeurLiee) {}

            public function etudiants(int $classeId, string $userToken): array
            {
                throw $this->panne === QueryException::class
                    ? new QueryException(
                        'sqlite',
                        'select * from classes where email = ?',
                        [$this->valeurLiee],
                        new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
                    )
                    : new RuntimeException('Panne interne de diagnostic #948.');
            }
        });
    }

    /** Comme `asTenant()`, mais le jeton porteur est gardé : il est un témoin. */
    private function porteur(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->tokenFor($this->enseignant);
    }

    private function lire(string $porteur): TestResponse
    {
        return $this->withToken($porteur)
            ->getJson('/api/lms/classes/'.self::CLASSE_ID.'/etudiants?token='.self::TEMOIN_REQUETE);
    }
}
