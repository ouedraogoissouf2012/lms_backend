<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /lms/classes/{classeId}/etudiants` : chaque réponse RÉELLE
 * suit ce que `docs/openapi.yaml` en dit, statut, corps et en-tête.
 *
 * KLASSCI est simulé par UN seul `Http::fake` par test (deux s'empilent, le
 * premier gagne) : c'est l'URL émise et la réponse amont qui décident, pas une
 * doublure du proxy. Les refus tournent pour les deux types d'école : créée
 * depuis KLASSCI, créée depuis le LMS (`standalone()`, sans URL KLASSCI). La
 * lecture, qui suppose un jeton KLASSCI, tourne pour une école créée depuis
 * KLASSCI. Vrai jeton porteur (`asTenant`).
 *
 * @see app/Http/Controllers/API/LMS/LMSClassesController.php
 * @see app/Http/Controllers/API/Concerns/RendersKlassciBackedErrors.php
 */
final class ClasseElevesContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/classes/{classeId}/etudiants';

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const ROUTE = '/api/lms/classes/42/etudiants';

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

    /** @return iterable<string, array{0: string}> */
    public static function rolesAdmis(): iterable
    {
        yield 'enseignant' => ['enseignant'];
        yield 'coordinateur' => ['coordinateur'];
        yield 'admin' => ['admin'];
    }

    #[DataProvider('rolesAdmis')]
    public function test_200_les_eleves_actifs_de_la_classe(string $role): void
    {
        $this->klassciRepond(['success' => true, 'data' => [
            'classe' => ['id' => 42],
            'etudiants' => [
                ['id' => 1, 'nom_complet' => 'DOE Ana', 'statut' => 'actif'],
                ['id' => 2, 'nom_complet' => 'SMITH Bo', 'statut' => 'inactif'],
                ['id' => 3, 'nom_complet' => 'FOO Cy'],
            ],
        ]]);

        $reponse = $this->lire($this->compte(false, $role))->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'data');
        $reponse->assertJsonPath('data.total', 2)->assertJsonStructure(['data' => ['etudiants', 'total']]);
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->getJson(self::ROUTE)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    /** Sans jeton KLASSCI, avant tout appel : un compte d'une école créée depuis le LMS n'en a jamais. */
    #[DataProvider('typesDEcole')]
    public function test_401_sans_jeton_klassci(bool $depuisLeLms): void
    {
        Http::fake();

        $reponse = $this->lire($this->compte($depuisLeLms, 'enseignant', null))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        Http::assertNothingSent();
    }

    public function test_401_klassci_refuse_la_session(): void
    {
        $this->klassciRepond(['message' => 'Unauthenticated.'], 401);

        $reponse = $this->lire($this->compte(false, 'enseignant'))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('reason', 'klassci_session_expired');
    }

    /** Refusé par la garde de rôle, pour les deux types d'école. */
    #[DataProvider('typesDEcole')]
    public function test_403_un_eleve(bool $depuisLeLms): void
    {
        $reponse = $this->lire($this->compte($depuisLeLms, 'etudiant'))->assertForbidden();

        $this->assertRefus($reponse, null);
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'enseignant']);

        $this->assertRefus($this->lire($compte)->assertForbidden(), 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);
        $compte = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        $this->assertRefus($this->lire($compte)->assertForbidden(), null);
    }

    /** @return iterable<string, array{0: int}> */
    public static function refusDeKlassci(): iterable
    {
        foreach ([400, 403, 404, 409, 422, 429] as $statut) {
            yield "KLASSCI rend $statut" => [$statut];
        }
    }

    /** Un refus de KLASSCI est relayé avec son statut et le message du catalogue. */
    #[DataProvider('refusDeKlassci')]
    public function test_un_refus_de_klassci_est_relaye(int $statut): void
    {
        $this->klassciRepond(['success' => false, 'message' => 'refus amont'], $statut);

        $reponse = $this->lire($this->compte(false, 'enseignant'))->assertStatus($statut);

        $this->assertRefus($reponse, null);
        self::assertNotSame('refus amont', $reponse->json('message'), 'le message amont n est jamais relayé tel quel');
    }

    /** @return iterable<string, array{0: array<string, mixed>|string, 1: int}> */
    public static function indisponibilites(): iterable
    {
        yield 'panne KLASSCI' => [['message' => 'panne'], 503];
        yield 'enveloppe sans roster' => [['success' => true, 'data' => ['classe' => ['id' => 42]]], 200];
        yield 'corps illisible' => ['<html>maintenance</html>', 200];
    }

    /** @param  array<string, mixed>|string  $corps */
    #[DataProvider('indisponibilites')]
    public function test_503_avec_retry_after(array|string $corps, int $statut): void
    {
        $this->klassciRepond($corps, $statut);

        $reponse = $this->lire($this->compte(false, 'enseignant'))->assertStatus(503)->assertHeader('Retry-After');

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');
    }

    /** @return iterable<string, array{0: \Throwable, 1: bool}> */
    public static function pannesInternes(): iterable
    {
        // Une RuntimeException hors 4xx : erreur sans détail, sans `error`.
        yield 'erreur sans détail' => [new RuntimeException('panne', 0), false];
        // Toute autre erreur : rattrapée par le contrôleur, avec `error`.
        yield 'erreur rattrapée' => [new LogicException('panne'), true];
    }

    #[DataProvider('pannesInternes')]
    public function test_500_une_panne_interne(\Throwable $panne, bool $avecError): void
    {
        $this->app->bind(ClasseRoster::class, static fn (): ClasseRoster => new class($panne) implements ClasseRoster
        {
            public function __construct(private readonly \Throwable $panne) {}

            public function etudiants(int $classeId, string $userToken): array
            {
                throw $this->panne;
            }
        });

        $reponse = $this->lire($this->compte(false, 'enseignant'))->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $avecError ? $reponse->assertJsonPath('error', 'Une erreur est survenue.') : $reponse->assertJsonMissingPath('error');
    }

    public function test_500_du_gestionnaire_global(): void
    {
        $reponse = $this->asTenant($this->compte(false, 'enseignant'))
            ->getJson('/api/lms/classes/abc/etudiants')
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonMissingPath('error');
    }

    /** UNE closure : `classes/42` rend le corps donné, tout le reste une enveloppe vide. */
    private function klassciRepond(array|string $corps, int $statut = 200): void
    {
        Http::fake(static function ($requete) use ($corps, $statut) {
            if (! str_contains($requete->url(), '/classes/42')) {
                return Http::response(['success' => true, 'data' => []]);
            }

            return Http::response($corps, $statut, is_string($corps) ? ['Content-Type' => 'text/html'] : []);
        });
    }

    private function compte(bool $depuisLeLms, string $role, ?string $jeton = 'jeton-klassci'): User
    {
        $ecole = $depuisLeLms
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['klassci_api_url' => self::BASE_URL, 'is_active' => true]);

        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_token' => $jeton,
            'klassci_tenant_url' => $depuisLeLms ? null : self::BASE_URL,
        ]);
    }

    private function lire(User $compte): TestResponse
    {
        return $this->asTenant($compte)->getJson(self::ROUTE);
    }

    /** Le refus suit le schéma ; son motif (ou son absence) est prouvé à part. */
    private function assertRefus(TestResponse $reponse, ?string $motif): void
    {
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');

        $motif === null ? $reponse->assertJsonMissingPath('reason') : $reponse->assertJsonPath('reason', $motif);
    }
}
