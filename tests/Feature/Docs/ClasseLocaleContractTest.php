<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Support\Klassci\KlassciClientErrorCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /lms/classes/local/{classeId}` : chaque réponse RÉELLE suit ce
 * que `docs/openapi.yaml` en dit, statut et corps.
 *
 * Le mode DÉCLARÉ de l'école choisit la source (ADR-760-01, amendement EI-02).
 * Dans une école créée depuis KLASSCI, la classe est résolue localement AVANT
 * tout jeton : une classe créée dans le LMS rend le 409 motivé, une classe qui a
 * une fiche KLASSCI est lue dans KLASSCI, simulé par UN seul `Http::fake` par
 * test. Une école créée depuis le LMS, ou basculée en mode autonome, lit la
 * fiche locale : `ClassesContractTest` en décrit la forme. Vrai jeton porteur
 * (`asTenant`).
 *
 * @see app/Services/Classe/ClasseLocalDetailsService.php
 */
final class ClasseLocaleContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/classes/local/{classeId}';

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const CLASSE_KLASSCI = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function typesDEcole(): iterable
    {
        yield 'école créée depuis KLASSCI' => [false];
        yield 'école créée depuis le LMS' => [true];
    }

    public function test_200_la_fiche_klassci_de_la_classe(): void
    {
        $this->klassciRepond(['success' => true, 'data' => [
            'classe' => ['id' => self::CLASSE_KLASSCI, 'nom' => 'BTS 1'],
            'etudiants' => [],
        ]]);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('data.classe.id', self::CLASSE_KLASSCI);
    }

    /**
     * Le mode DÉCLARÉ décide : une école créée depuis KLASSCI, basculée en mode
     * autonome, lit la fiche locale par l'id local, sans aucun appel à KLASSCI.
     * Cas rare (la bascule), joué parce que ses classes gardent leur `klassci_id`.
     */
    public function test_200_une_ecole_basculee_lit_la_fiche_locale(): void
    {
        Http::fake();
        $ecole = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'is_active' => true,
            'mode' => InstitutionMode::Standalone,
        ]);
        $classe = $this->classe($ecole, self::CLASSE_KLASSCI);

        $reponse = $this->lire($this->compte($ecole, 'jeton-klassci', 'coordinateur'), $classe->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('data.classe.id', $classe->id);
        Http::assertNothingSent();
    }

    /** Sans jeton porteur, un en-tête `X-Institution` inconnu rend 400, avant l'authentification. */
    public function test_400_une_ecole_inconnue_sans_jeton_porteur(): void
    {
        $reponse = $this->withHeader('X-Institution', 'ecole-inconnue')->getJson('/api/lms/classes/local/1')->assertStatus(400);

        $this->assertRefus($reponse, null);
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->getJson('/api/lms/classes/local/1')->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    /** Une classe qui a une fiche KLASSCI, un compte sans jeton : le 401 motivé, avant tout appel. */
    public function test_401_sans_jeton_klassci(): void
    {
        Http::fake();
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole, null), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        Http::assertNothingSent();
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'enseignant']);

        $reponse = $this->lire($compte, 1)->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');
        $reponse->assertJsonPath('reason', 'no_institution');
    }

    /** Le gestionnaire de la plateforme n'entre pas dans une école, même rattaché à l'une d'elles. */
    public function test_403_un_supradmin(): void
    {
        $reponse = $this->lire($this->compte($this->ecole(false), 'jeton-klassci', 'supradmin'), 1)->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('reason', 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);

        $reponse = $this->lire($this->compte($ecole), 1)->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonMissingPath('reason');
    }

    /**
     * Aucune classe de cet identifiant dans l'établissement : celle d'une autre
     * école ne se résout pas. Un coordinateur, qui lit toute classe de SON école :
     * le 404 vient du bornage à l'établissement, pas de la grille d'accès.
     */
    #[DataProvider('typesDEcole')]
    public function test_404_une_classe_d_une_autre_ecole(bool $depuisLeLms): void
    {
        $etrangere = $this->classe($this->ecole($depuisLeLms), null);

        $reponse = $this->lire($this->compte($this->ecole($depuisLeLms), 'jeton-klassci', 'coordinateur'), $etrangere->id)->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /** KLASSCI répond 2xx SANS fiche de classe : le même 404, sans motif. Un 404 HTTP de KLASSCI est relayé. */
    public function test_404_klassci_ne_rend_pas_la_fiche(): void
    {
        $this->klassciRepond(['success' => true, 'data' => []]);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /**
     * Une classe créée dans le LMS, dans une école créée depuis KLASSCI : le 409
     * motivé, même sans jeton KLASSCI. Une école créée depuis le LMS lit la fiche
     * locale, sans 409 (`ClassesContractTest`).
     */
    public function test_409_une_classe_creee_dans_le_lms(): void
    {
        Http::fake();
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole, null), $this->classe($ecole, null)->id)->assertStatus(409);

        $this->assertRefus($reponse, 'classe_local_only');
        Http::assertNothingSent();
    }

    /** Pas de garde de rôle : un élève reçoit la même réponse, dans une école créée depuis KLASSCI. */
    public function test_409_un_eleve_aussi(): void
    {
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole, null, 'etudiant'), $this->classe($ecole, null)->id)->assertStatus(409);

        $this->assertRefus($reponse, 'classe_local_only');
    }

    /** @return iterable<string, array{0: int}> */
    public static function refusDeKlassci(): iterable
    {
        yield 'refus HTTP (403)' => [403];
        yield 'refus HTTP (404)' => [404];
    }

    /**
     * Un refus de KLASSCI est relayé avec son statut et le message du catalogue,
     * comme pour les élèves de la classe. Il rendait le 500 de la lecture.
     */
    #[DataProvider('refusDeKlassci')]
    public function test_un_refus_de_klassci_est_relaye(int $statut): void
    {
        $this->klassciRepond(['success' => false, 'message' => 'refus amont'], $statut);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertStatus($statut);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('message', KlassciClientErrorCatalog::messageFor($statut))->assertJsonMissingPath('reason');
    }

    public function test_401_klassci_refuse_la_session(): void
    {
        $this->klassciRepond(['message' => 'Unauthenticated.'], 401);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('reason', 'klassci_session_expired');
    }

    /** @return iterable<string, array{0: array<string, mixed>|string, 1: int}> */
    public static function indisponibilites(): iterable
    {
        yield 'panne (503)' => [['message' => 'panne'], 503];
        yield 'corps illisible' => ['<html>maintenance</html>', 200];
    }

    /**
     * Une panne de KLASSCI ou une réponse illisible rend le 503 avec `Retry-After`.
     * Elle rendait le 500 de la lecture, sans délai de reprise.
     *
     * @param  array<string, mixed>|string  $corps
     */
    #[DataProvider('indisponibilites')]
    public function test_503_avec_retry_after(array|string $corps, int $statut): void
    {
        $this->klassciRepond($corps, $statut);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) KlassciUnavailableException::retryAfterSeconds());

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');
        // Le 503 vient bien de la lecture de CETTE classe, pas du repli de la closure.
        Http::assertSent(static fn ($requete): bool => str_contains($requete->url(), '/classes/'.self::CLASSE_KLASSCI));
    }

    /** La route contraint l'identifiant (`whereNumber`) : un identifiant non numérique ne la trouve pas. */
    public function test_404_un_identifiant_non_numerique(): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole(false)))
            ->getJson('/api/lms/classes/local/abc')
            ->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /**
     * Une erreur qui n'est pas un échec de lecture n'est pas rattrapée : le 500 du
     * gestionnaire global, sans `error`. Une `LogicException`, parce qu'une
     * `RuntimeException` passe désormais par le rendu des échecs KLASSCI
     * (`FicheClasseEchecKlassciTest` couvre ce cas).
     */
    public function test_500_une_erreur_non_rattrapee(): void
    {
        $this->app->when(ClasseDetailsQueryService::class)->needs(LoggerInterface::class)->give(
            static fn (): LoggerInterface => new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    throw new LogicException('panne provoquée par le test');
                }
            },
        );
        Http::fake();
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonMissingPath('error');
        self::assertNotSame('Erreur lors de la récupération de la classe', $reponse->json('message'), 'le gestionnaire global, pas le catch de la lecture');
        Http::assertNothingSent();
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
     * Faute de colonne de type, une école créée depuis le LMS se modélise comme
     * SchoolRequestDecisionService la crée : mode standalone, sans URL KLASSCI.
     */
    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]);
    }

    private function compte(Institution $ecole, ?string $jeton = 'jeton-klassci', string $role = 'enseignant'): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_token' => $jeton,
            'klassci_tenant_url' => $ecole->klassci_api_url,
        ]);
    }

    private function classe(Institution $ecole, ?int $klassciId): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => $klassciId]);
    }

    private function lire(User $compte, int $classeId): TestResponse
    {
        return $this->asTenant($compte)->getJson("/api/lms/classes/local/{$classeId}");
    }

    /** Le refus suit le schéma ; son motif (ou son absence) est prouvé à part. */
    private function assertRefus(TestResponse $reponse, ?string $motif): void
    {
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');

        $motif === null ? $reponse->assertJsonMissingPath('reason') : $reponse->assertJsonPath('reason', $motif);
    }
}
