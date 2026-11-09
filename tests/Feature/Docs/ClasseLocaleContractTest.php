<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseDetailsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /lms/classes/local/{classeId}` : chaque réponse RÉELLE suit ce
 * que `docs/openapi.yaml` en dit, statut et corps.
 *
 * La classe est résolue localement AVANT tout jeton : une classe créée dans le
 * LMS rend le 409 motivé dans les deux types d'école, créée depuis KLASSCI ou
 * créée depuis le LMS. Une classe qui a une fiche KLASSCI est lue dans KLASSCI,
 * simulé par UN seul `Http::fake` par test. Le 200 reste un schéma ouvert : la
 * forme de la fiche n'est pas encore décrite. Vrai jeton porteur (`asTenant`).
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

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);

        $reponse = $this->lire($this->compte($ecole), 1)->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonMissingPath('reason');
    }

    /** Aucune classe de cet identifiant dans l'établissement : celle d'une autre école ne se résout pas. */
    #[DataProvider('typesDEcole')]
    public function test_404_une_classe_d_une_autre_ecole(bool $depuisLeLms): void
    {
        $etrangere = $this->classe($this->ecole($depuisLeLms), null);

        $reponse = $this->lire($this->compte($this->ecole($depuisLeLms)), $etrangere->id)->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /** KLASSCI ne rend pas la fiche de la classe : le même 404, sans motif. */
    public function test_404_klassci_ne_rend_pas_la_fiche(): void
    {
        $this->klassciRepond(['success' => true, 'data' => []]);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /** Une classe créée dans le LMS, dans les deux types d'école : le 409 motivé, même sans jeton KLASSCI. */
    #[DataProvider('typesDEcole')]
    public function test_409_une_classe_creee_dans_le_lms(bool $depuisLeLms): void
    {
        Http::fake();
        $ecole = $this->ecole($depuisLeLms);

        $reponse = $this->lire($this->compte($ecole, null), $this->classe($ecole, null)->id)->assertStatus(409);

        $this->assertRefus($reponse, 'classe_local_only');
        Http::assertNothingSent();
    }

    /** Toute erreur de KLASSCI pendant la lecture rend ce 500, sans motif ni `error` (décrit tel quel). */
    public function test_500_une_panne_de_klassci(): void
    {
        $this->klassciRepond(['message' => 'panne'], 503);
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson(['success' => false, 'message' => 'Erreur lors de la récupération de la classe'])
            ->assertHeaderMissing('Retry-After');
    }

    /** La route contraint l'identifiant (`whereNumber`) : un identifiant non numérique ne la trouve pas. */
    public function test_404_un_identifiant_non_numerique(): void
    {
        $reponse = $this->asTenant($this->compte($this->ecole(false)))
            ->getJson('/api/lms/classes/local/abc')
            ->assertNotFound();

        $this->assertRefus($reponse, null);
    }

    /** Une erreur hors de la lecture KLASSCI n'est pas rattrapée : le 500 du gestionnaire global, sans `error`. */
    public function test_500_une_erreur_non_rattrapee(): void
    {
        $this->app->when(ClasseDetailsQueryService::class)->needs(LoggerInterface::class)->give(
            static fn (): LoggerInterface => new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    throw new RuntimeException('panne provoquée par le test');
                }
            },
        );
        $ecole = $this->ecole(false);

        $reponse = $this->lire($this->compte($ecole), $this->classe($ecole, self::CLASSE_KLASSCI)->id)->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonMissingPath('error');
    }

    /** UNE closure : `classes/42` rend le corps donné, tout le reste une enveloppe vide. */
    private function klassciRepond(array $corps, int $statut = 200): void
    {
        Http::fake(static function ($requete) use ($corps, $statut) {
            if (! str_contains($requete->url(), '/classes/'.self::CLASSE_KLASSCI)) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response($corps, $statut);
        });
    }

    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]);
    }

    private function compte(Institution $ecole, ?string $jeton = 'jeton-klassci'): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'enseignant',
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
