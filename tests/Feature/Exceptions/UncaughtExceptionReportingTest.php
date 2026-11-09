<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use App\Exceptions\UncaughtExceptionReporting;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #948 — ce que le journal garde d'une exception non attrapée.
 *
 * Avant : le rendu JSON générique de `bootstrap/app.php` écrivait TOUTE exception
 * en `error` avec sa trace, y compris un 404 ou un 405, et une panne une seconde
 * fois après le rapport de Laravel.
 *
 * Après : une panne (5xx) est écrite une seule fois en `error`, avec la trace ; un
 * refus d'accès (403) en `warning`, sans trace, signal de sondage d'accès ; les
 * autres 4xx ne le sont plus.
 *
 * Rien de la requête n'est écrit hormis sa route, sa méthode et son identifiant :
 * ni corps, ni chaîne de requête, ni en-tête `Authorization`. Une trace ne porte
 * pas les arguments des fonctions. Une exception SQL est écrite sans les valeurs
 * de la requête : son message les porte, il n'est pas écrit. Des témoins sont
 * envoyés par chacun de ces chemins, et le journal formaté ne doit en contenir
 * aucun.
 *
 * @see app/Exceptions/UncaughtExceptionReporting.php
 * @see bootstrap/app.php withExceptions()
 */
final class UncaughtExceptionReportingTest extends TestCase
{
    use ActsAsTenantUser;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const TEMOIN_REQUETE = 'temoin-requete-948-jamais-ecrit';

    private const TEMOIN_CORPS = 'temoin-corpsrq-948-jamais-ecrit';

    private const TEMOIN_ENTETE = 'temoin-entetes-948-jamais-ecrit';

    private const TEMOIN_ARGUMENT = 'temoin-argumnt-948-jamais-ecrit';

    /** Une adresse factice, liée à une requête SQL qui échoue. */
    private const TEMOIN_SQL = 'temoin.sql.948@exemple.invalid';

    /** Ce qu'écrit l'échec d'une requête SQL, et rien d'autre : ni message, ni valeurs. */
    private const CONTEXTE_D_UN_ECHEC_SQL = [
        'code', 'connection', 'driver_code', 'exception_class', 'method', 'request_id', 'route', 'sql', 'trace',
    ];

    /** Ce que le rapport d'une requête peut porter, et rien d'autre. */
    private const CONTEXTE_D_UNE_REQUETE = ['method', 'request_id', 'route'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.debug', false);
        $this->captureWrittenLog();

        Route::middleware('api')->prefix('api/_948')->group(function (): void {
            Route::get('modele-absent', fn () => Lesson::query()->findOrFail(PHP_INT_MAX));
            Route::get('lecture-seule', fn () => ['ok' => true]);
            Route::get('refus', fn () => abort(403));
            Route::post('panne', fn () => throw new RuntimeException('Panne de diagnostic #948.'));
            Route::get('panne-sous-argument', fn () => self::echouerAvec(self::TEMOIN_ARGUMENT));
            Route::get('indisponible', fn () => abort(503));
            Route::get('doublon', fn () => throw new MultipleRecordsFoundException(2));
            Route::get('requete-sql', fn () => self::requeteQuiEchoue());
            Route::get('interblocage', fn () => self::interblocage());
        });
    }

    public function test_une_route_absente_n_est_pas_journalisee(): void
    {
        $this->getJson('/api/route-inexistante-sonde?token='.self::TEMOIN_REQUETE)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        self::assertSame([], $this->rapports());
    }

    public function test_un_modele_absent_n_est_pas_journalise(): void
    {
        $this->getJson('/api/_948/modele-absent')->assertNotFound();

        self::assertSame([], $this->rapports());
    }

    public function test_une_methode_refusee_n_est_pas_journalisee(): void
    {
        $this->postJson('/api/_948/lecture-seule')->assertStatus(405);

        self::assertSame([], $this->rapports());
    }

    /** @return iterable<string, array{0: bool}> */
    public static function typesDEcole(): iterable
    {
        yield 'école créée depuis KLASSCI' => [false];
        yield 'école créée depuis le LMS' => [true];
    }

    /**
     * Le chemin réel d'un refus levé en exception : l'admin passe le middleware
     * de rôle de la route, puis `StoreChapterRequest::authorize()` le refuse
     * (seuls un enseignant ou un coordinateur créent un chapitre). Le rapporteur
     * reçoit alors une `AuthorizationException` brute.
     */
    #[DataProvider('typesDEcole')]
    public function test_un_refus_de_formrequest_donne_un_seul_warning_sans_trace(bool $depuisLeLms): void
    {
        $this->disableKlassciMiddleware();
        $ecole = $depuisLeLms
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $admin = User::factory()->admin()->create(['institution_id' => $ecole->id]);

        $this->asTenant($admin)
            ->postJson('/api/lessons/1/chapters?token='.self::TEMOIN_REQUETE, ['title' => self::TEMOIN_CORPS])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $this->assertUnSeulRefus('api/lessons/1/chapters', 'POST');
        $this->assertNoWitnessWritten(self::TEMOIN_REQUETE, self::TEMOIN_CORPS);
    }

    public function test_une_http_exception_403_donne_un_seul_warning_sans_trace(): void
    {
        $this->asTenant(User::factory()->create())
            ->getJson('/api/_948/refus?token='.self::TEMOIN_REQUETE)
            ->assertForbidden();

        $this->assertUnSeulRefus('api/_948/refus', 'GET');
        $this->assertNoWitnessWritten(self::TEMOIN_REQUETE);
    }

    public function test_une_panne_est_journalisee_une_seule_fois_en_error_avec_la_trace(): void
    {
        $this->withHeader('Authorization', 'Bearer '.self::TEMOIN_ENTETE)
            ->postJson('/api/_948/panne?token='.self::TEMOIN_REQUETE, ['mot_de_passe' => self::TEMOIN_CORPS])
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $panne = $this->uneSeulePanne();
        self::assertSame('api/_948/panne', $panne->context['route'] ?? null);
        self::assertSame('POST', $panne->context['method'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_REQUETE, self::TEMOIN_CORPS, self::TEMOIN_ENTETE);
    }

    /** Une trace garde le fichier, la ligne et la fonction ; jamais les valeurs passées. */
    public function test_la_trace_d_une_panne_ne_porte_pas_les_arguments_des_fonctions(): void
    {
        $this->getJson('/api/_948/panne-sous-argument')->assertStatus(500);

        $this->uneSeulePanne();
        $this->assertNoWitnessWritten(self::TEMOIN_ARGUMENT);
    }

    public function test_un_abort_503_reste_journalise_en_error(): void
    {
        $this->getJson('/api/_948/indisponible')->assertStatus(503);

        $enregistrements = $this->rapports();
        self::assertCount(1, $enregistrements);
        self::assertSame(Level::Error, $enregistrements[0]->level);
    }

    /**
     * Un `sole()` qui trouve des doublons : Laravel tait cette exception
     * (`internalDontReport`), mais aucun rendu ne la convertit en 4xx. C'est un
     * 500, donc une panne, qu'aucun journal n'écrirait sinon.
     */
    public function test_des_doublons_sous_sole_sont_une_panne_journalisee(): void
    {
        $this->getJson('/api/_948/doublon')->assertStatus(500);

        self::assertInstanceOf(MultipleRecordsFoundException::class, $this->uneSeulePanne()->context['exception']);
    }

    /**
     * Le message d'une `QueryException` porte le SQL avec ses valeurs liées : une
     * adresse, ici. Une requête réelle échoue sur la base de test ; le journal
     * écrit garde le SQL à marqueurs, jamais la valeur.
     */
    public function test_une_exception_sql_est_ecrite_une_fois_sans_les_valeurs_de_la_requete(): void
    {
        $this->getJson('/api/_948/requete-sql')
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $enregistrements = $this->rapports();
        self::assertCount(1, $enregistrements, 'Une exception SQL doit être écrite une fois, pas deux.');
        $echec = $enregistrements[0];
        self::assertSame(Level::Error, $echec->level);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
        self::assertSame(UncaughtExceptionReporting::QUERY_FAILURE_MESSAGE, $echec->message);
        $contexte = array_keys($echec->context);
        sort($contexte);
        self::assertSame(self::CONTEXTE_D_UN_ECHEC_SQL, $contexte, 'Ni l\'exception (son message), ni les valeurs, ni le compte.');
        self::assertSame(QueryException::class, $echec->context['exception_class']);
        self::assertSame('api/_948/requete-sql', $echec->context['route']);
        self::assertIsString($echec->context['sql']);
        self::assertStringContainsString('table_absente_948', $echec->context['sql']);
        self::assertStringContainsString('= ?', $echec->context['sql']);
        self::assertNotSame('', $echec->context['trace']);
        self::assertStringContainsString('= ?', (new LineFormatter(null, null, true, true, true))->format($echec));
    }

    /**
     * Un interblocage dans une transaction imbriquée : Laravel enveloppe la
     * `QueryException` dans une `DeadlockException`, qui n'en est pas une, en
     * recopiant son message (`ManagesTransactions::handleTransactionException`).
     * Le formateur écrirait ce message, et la chaîne `previous` avec lui.
     */
    public function test_une_exception_sql_enveloppee_est_ecrite_sans_les_valeurs_de_la_requete(): void
    {
        $this->getJson('/api/_948/interblocage')->assertStatus(500);

        $enregistrements = $this->rapports();
        self::assertCount(1, $enregistrements, 'Une exception SQL doit être écrite une fois, pas deux.');
        $echec = $enregistrements[0];
        self::assertSame(Level::Error, $echec->level);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
        self::assertSame(UncaughtExceptionReporting::QUERY_FAILURE_MESSAGE, $echec->message);
        $contexte = array_keys($echec->context);
        sort($contexte);
        self::assertSame(self::CONTEXTE_D_UN_ECHEC_SQL, $contexte);
        self::assertSame(DeadlockException::class, $echec->context['exception_class']);
        self::assertIsString($echec->context['sql']);
        self::assertStringContainsString('= ?', $echec->context['sql']);
    }

    private static function requeteQuiEchoue(): never
    {
        DB::table('table_absente_948')->where('email', self::TEMOIN_SQL)->get();

        throw new RuntimeException('La requête devait échouer : la table est absente.');
    }

    /** Ce que fait `ManagesTransactions` d'un interblocage dans une transaction imbriquée. */
    private static function interblocage(): never
    {
        try {
            self::requeteQuiEchoue();
        } catch (QueryException $e) {
            throw new DeadlockException($e->getMessage(), 0, $e);
        }
    }

    private static function echouerAvec(string $valeur): never
    {
        throw new RuntimeException('Panne de diagnostic #948, sous un argument de '.strlen($valeur).' caractères.');
    }

    /**
     * Ce qu'écrit le rapport d'une exception : un enregistrement qui porte
     * l'exception, qui est le refus d'accès, ou qui atteint `error`. Le `warning`
     * du scope de tenant (`BelongsToInstitution`, écrit pendant l'authentification
     * par jeton porteur ou par une requête anonyme), étranger à #948, n'en est pas.
     *
     * @return list<LogRecord>
     */
    private function rapports(): array
    {
        return array_values(array_filter(
            $this->recordsAtLeast(Level::Debug),
            static fn (LogRecord $enregistrement): bool => array_key_exists('exception', $enregistrement->context)
                || $enregistrement->message === UncaughtExceptionReporting::ACCESS_DENIED_MESSAGE
                || $enregistrement->level->value >= Level::Error->value,
        ));
    }

    private function uneSeulePanne(): LogRecord
    {
        $enregistrements = $this->rapports();
        self::assertCount(1, $enregistrements, 'Une panne doit être écrite une fois, pas deux.');
        $panne = $enregistrements[0];

        self::assertSame(Level::Error, $panne->level);
        self::assertInstanceOf(RuntimeException::class, $panne->context['exception'] ?? null);
        $contexte = array_keys($panne->context);
        sort($contexte);
        self::assertSame(['exception', ...self::CONTEXTE_D_UNE_REQUETE], $contexte, 'Une panne anonyme : la requête, rien d\'autre.');
        self::assertNotEmpty($panne->context['request_id']);

        return $panne;
    }

    private function assertUnSeulRefus(string $route, string $methode): void
    {
        $enregistrements = $this->rapports();
        self::assertCount(1, $enregistrements, 'Un refus doit être écrit une fois.');
        $refus = $enregistrements[0];

        self::assertSame(Level::Warning, $refus->level);
        self::assertSame(UncaughtExceptionReporting::ACCESS_DENIED_MESSAGE, $refus->message);
        $contexte = array_keys($refus->context);
        sort($contexte);
        self::assertSame(self::CONTEXTE_D_UNE_REQUETE, $contexte, 'Un refus : ni trace, ni compte, ni donnée de la requête.');
        self::assertSame($route, $refus->context['route']);
        self::assertSame($methode, $refus->context['method']);
        self::assertNotEmpty($refus->context['request_id']);
    }
}
