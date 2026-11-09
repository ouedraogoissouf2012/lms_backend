<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseDetailsReader;
use App\Services\Classe\ClasseEtudiantsQueryService;
use App\Services\Classe\ClasseEtudiantsReader;
use App\Services\Classe\ClasseLocalDetailsService;
use App\Services\Classe\ClasseLocalDoorReader;
use App\Services\Classe\LocalClasseDetailsReader;
use App\Support\Klassci\KlassciClientErrorCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use LogicException;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * Les échecs de la fiche d'une classe, par ses deux portes (`lms/classes/{id}`
 * et `lms/classes/local/{id}`).
 *
 * ## Dans une école créée depuis KLASSCI
 *
 * La lecture de la fiche rattrapait toute erreur en 500 : un refus de KLASSCI
 * devenait une panne, et une panne n'annonçait aucun délai de reprise. Elle
 * suit désormais la règle des élèves de la classe
 * (`RendersKlassciBackedErrors`) : un refus 4xx relayé avec le message du
 * catalogue, une panne ou une réponse illisible en 503 avec `Retry-After`.
 *
 * Une `RuntimeException` qui ne vient pas de KLASSCI passe par la même branche :
 * le 500 générique. Elle doit rester RAPPORTÉE, sans quoi la panne deviendrait
 * muette.
 *
 * ## Dans les deux types d'école
 *
 * Une panne de base est une `RuntimeException` (par `PDOException`) : elle passe
 * par la branche 500 du même trait, qui la RAPPORTE une fois (#948), sans le
 * message ni les valeurs de sa requête. Le journal est lu tel qu'il est ÉCRIT
 * (`CapturesWrittenLog`), avec un témoin lié qui ne doit jamais y figurer. Les
 * erreurs qui ne sont pas des `RuntimeException` passent par le filet du
 * contrôleur, rapportées une fois elles aussi.
 *
 * KLASSCI est simulé par UN seul `Http::fake` par test. Vrai jeton porteur
 * (`asTenant`).
 */
final class FicheClasseEchecsTest extends TestCase
{
    use ActsAsTenantUser;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const CLASSE_KLASSCI = 42;

    /** Une valeur liée à la requête SQL qui échoue : elle ne doit jamais être écrite. */
    private const TEMOIN_SQL = 'temoin.sql.lot2@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
    }

    /** @return iterable<string, array{0: string}> */
    public static function portes(): iterable
    {
        yield 'porte historique' => ['historique'];
        yield 'porte locale' => ['locale'];
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function refusParPorte(): iterable
    {
        foreach (['historique', 'locale'] as $porte) {
            foreach ([400, 403, 404, 422] as $statut) {
                yield "porte $porte, KLASSCI rend $statut" => [$porte, $statut];
            }
        }
    }

    // ───────────────────────── école créée depuis KLASSCI : les échecs de KLASSCI

    #[DataProvider('refusParPorte')]
    public function test_un_refus_de_klassci_est_relaye(string $porte, int $statut): void
    {
        $this->klassciRepond(['success' => false, 'message' => 'refus amont'], $statut);

        $this->lire($porte)
            ->assertStatus($statut)
            ->assertExactJson(['success' => false, 'message' => KlassciClientErrorCatalog::messageFor($statut)]);
    }

    #[DataProvider('portes')]
    public function test_une_session_refusee_par_klassci_porte_son_motif(string $porte): void
    {
        $this->klassciRepond(['message' => 'Unauthenticated.'], 401);

        $this->lire($porte)->assertUnauthorized()->assertJsonPath('reason', 'klassci_session_expired');
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>|string, 2: int}> */
    public static function indisponibilites(): iterable
    {
        foreach (['historique', 'locale'] as $porte) {
            yield "porte $porte, panne" => [$porte, ['message' => 'panne'], 503];
            yield "porte $porte, corps illisible" => [$porte, '<html>maintenance</html>', 200];
        }
    }

    /** @param  array<string, mixed>|string  $corps */
    #[DataProvider('indisponibilites')]
    public function test_une_panne_de_klassci_rend_503_avec_retry_after(string $porte, array|string $corps, int $statut): void
    {
        $this->klassciRepond($corps, $statut);

        $this->lire($porte)
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) KlassciUnavailableException::retryAfterSeconds());
    }

    /**
     * Une `RuntimeException` de code 0 n'est pas un échec de KLASSCI : le 500
     * générique, et l'erreur reste rapportée au gestionnaire d'exceptions.
     */
    #[DataProvider('portes')]
    public function test_une_runtime_exception_non_klassci_rend_le_500_generique_et_est_rapportee(string $porte): void
    {
        Exceptions::fake();
        Http::fake();
        $this->app->when(ClasseDetailsQueryService::class)->needs(LoggerInterface::class)->give(
            static fn (): LoggerInterface => new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    throw new RuntimeException('panne interne provoquée par le test');
                }
            },
        );

        $this->lire($porte)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        Exceptions::assertReported(
            static fn (RuntimeException $e): bool => $e->getMessage() === 'panne interne provoquée par le test',
        );
    }

    // ───────────────────────── les deux types d'école : une panne interne

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function voletsEtPortes(): iterable
    {
        foreach ([InstitutionMode::Klassci, InstitutionMode::Standalone] as $mode) {
            foreach (['historique', 'locale', 'eleves'] as $route) {
                yield "école {$mode->value}, $route" => [$mode, $route];
            }
        }
    }

    #[DataProvider('voletsEtPortes')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(InstitutionMode $mode, string $route): void
    {
        $this->sourcesEnPanne($mode, new QueryException(
            'sqlite',
            'select * from classes where email = ?',
            [self::TEMOIN_SQL],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        ));
        $this->captureWrittenLog();

        $this->asTenant($this->coordinateur($mode))->getJson($this->url($route))
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame(QueryException::class, $ecrits[0]->context['exception_class'] ?? null);
        self::assertSame('select * from classes where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function filets(): iterable
    {
        foreach ([InstitutionMode::Klassci, InstitutionMode::Standalone] as $mode) {
            foreach (['historique', 'eleves'] as $route) {
                yield "école {$mode->value}, $route" => [$mode, $route];
            }
        }
    }

    /**
     * Le filet du contrôleur (porte historique et élèves) : une erreur qui n'est
     * pas une `RuntimeException` est rapportée une fois, et le corps garde `error`.
     */
    #[DataProvider('filets')]
    public function test_une_erreur_hors_runtime_exception_est_rapportee_une_fois(InstitutionMode $mode, string $route): void
    {
        $this->sourcesEnPanne($mode, new LogicException('panne interne de diagnostic'));
        $this->captureWrittenLog();

        $this->asTenant($this->coordinateur($mode))->getJson($this->url($route))
            ->assertStatus(500)
            ->assertJsonPath('error', 'Une erreur est survenue.');

        self::assertCount(1, $this->recordsAtLeast(Level::Error), 'La panne doit être écrite une fois.');
    }

    // ───────────────────────── harnais

    /**
     * Les sources sont `final` : une doublure qui implémente les ports prend la
     * place de celles que la fabrique résoudra pour ce type d'école.
     */
    private function sourcesEnPanne(InstitutionMode $mode, Throwable $panne): void
    {
        $doublure = new class($panne) implements ClasseDetailsReader, ClasseEtudiantsReader, ClasseLocalDoorReader
        {
            public function __construct(private readonly Throwable $panne) {}

            public function getDetailsForUser(int $classeId, User $user): array
            {
                throw $this->panne;
            }

            public function detailsForLocalId(int $classeId, User $user): array
            {
                throw $this->panne;
            }

            public function getEtudiants(int $classeId, User $user): array
            {
                throw $this->panne;
            }
        };

        $sources = $mode === InstitutionMode::Klassci
            ? [ClasseDetailsQueryService::class, ClasseLocalDetailsService::class, ClasseEtudiantsQueryService::class]
            : [LocalClasseDetailsReader::class];

        foreach ($sources as $source) {
            $this->app->instance($source, $doublure);
        }
    }

    private function coordinateur(InstitutionMode $mode): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'coordinateur',
            'klassci_token' => $mode === InstitutionMode::Klassci ? 'jeton-klassci' : null,
        ]);
    }

    private function url(string $route): string
    {
        return match ($route) {
            'historique' => '/api/lms/classes/7',
            'locale' => '/api/lms/classes/local/7',
            default => '/api/lms/classes/7/etudiants',
        };
    }

    /** UNE closure : `classes/42` rend le corps donné, tout le reste une enveloppe vide. */
    private function klassciRepond(array|string $corps, int $statut = 200): void
    {
        Http::fake(static function ($requete) use ($corps, $statut) {
            if (! str_contains($requete->url(), '/classes/'.self::CLASSE_KLASSCI)) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response($corps, $statut, is_string($corps) ? ['Content-Type' => 'text/html'] : []);
        });
    }

    /**
     * Une école créée depuis KLASSCI, un enseignant porteur d'un jeton, et la
     * classe de fiche KLASSCI 42. La porte historique parle l'espace KLASSCI, la
     * porte locale l'id local.
     */
    private function lire(string $porte): TestResponse
    {
        $ecole = Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::CLASSE_KLASSCI]);
        $compte = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'enseignant',
            'klassci_token' => 'jeton-klassci',
            'klassci_tenant_url' => self::BASE_URL,
        ]);

        return $this->asTenant($compte)->getJson($porte === 'historique'
            ? '/api/lms/classes/'.self::CLASSE_KLASSCI
            : '/api/lms/classes/local/'.$classe->id);
    }
}
