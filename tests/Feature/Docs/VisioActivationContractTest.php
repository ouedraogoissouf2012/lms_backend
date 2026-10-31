<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `POST /lms/seances/{seanceId}/activate-visio` : chaque réponse
 * RÉELLE suit ce que `docs/openapi.yaml` en dit, statut et corps.
 *
 * Les refus tournent dans les DEUX volets. Le 200, le 404 et le 500 de panne
 * KLASSCI ne tournent que dans le volet KLASSCI : ils supposent un jeton
 * KLASSCI. Dans le volet autonome, le compte est créé SANS jeton, comme tout
 * compte créé dans une école autonome (`UserFactory` en donne un à tout
 * compte). La route ne lit pas le mode de l'école : un compte qui garde son
 * jeton après le passage de son école en autonome n'est pas refusé, ce que la
 * spec dit. Vrai jeton porteur (`asTenant`).
 *
 * @see app/Services/Visio/Lifecycle/VisioActivationService.php
 */
final class VisioActivationContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances/{seanceId}/activate-visio';

    private const JETON_KLASSCI = 'jeton-klassci-enseignant';

    private const SEANCE_KLASSCI = 349;

    private const MATIERE_KLASSCI = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** @return iterable<string, array{0: string}> */
    public static function rolesAdmis(): iterable
    {
        yield 'enseignant' => ['enseignant'];
        yield 'coordinateur' => ['coordinateur'];
    }

    #[DataProvider('rolesAdmis')]
    public function test_200_la_seance_est_dans_l_emploi_du_temps_du_porteur(string $role): void
    {
        $this->klassciConnaitLaSeance();
        $compte = $this->compte($this->ecole(false), $role, self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
        $this->assertDatabaseHas('seances', ['klassci_seance_id' => self::SEANCE_KLASSCI, 'visio_enabled' => true]);
    }

    /** La spec le dit : un salon déjà attribué est conservé. */
    public function test_200_un_salon_deja_attribue_est_conserve(): void
    {
        $this->klassciConnaitLaSeance();
        $ecole = $this->ecole(false);
        Seance::factory()->withVisio()->create([
            'institution_id' => $ecole->id,
            'klassci_seance_id' => self::SEANCE_KLASSCI,
            'visio_room_id' => 'salon-deja-attribue',
        ]);
        $compte = $this->compte($ecole, 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('data.visio_room_id', 'salon-deja-attribue');
    }

    /** Un compte sans jeton KLASSCI : le 401 motivé de #806, et rien n'est écrit. */
    #[DataProvider('volets')]
    public function test_401_sans_jeton_klassci(bool $autonome): void
    {
        $compte = $this->compte($this->ecole($autonome), 'enseignant', null);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->postJson($this->url(self::SEANCE_KLASSCI))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function rolesRefuses(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            yield "étudiant, $volet" => [$autonome, 'etudiant'];
            yield "admin, $volet" => [$autonome, 'admin'];
            // La garde de rôle laisse passer superAdmin ; la requête le refuse.
            yield "superAdmin, $volet" => [$autonome, 'superAdmin'];
        }
    }

    #[DataProvider('rolesRefuses')]
    public function test_403_un_role_non_admis(bool $autonome, string $role): void
    {
        $compte = $this->compte($this->ecole($autonome), $role, $autonome ? null : self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'enseignant', 'klassci_token' => self::JETON_KLASSCI]);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        $reponse->assertJsonPath('reason', 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);
        $compte = $this->compte($ecole, 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('reason');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** L'identifiant n'est pas dans l'emploi du temps des matières du porteur. */
    public function test_404_une_seance_hors_de_l_emploi_du_temps_du_porteur(): void
    {
        $this->klassciConnaitLaSeance();
        $compte = $this->compte($this->ecole(false), 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI + 1))->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        $reponse->assertExactJson(['success' => false, 'message' => 'Séance non trouvée']);
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /**
     * Une panne de KLASSCI pendant la recherche rend le 500 décrit, sans motif
     * ni `Retry-After` : c'est le comportement que la spec déclare.
     */
    public function test_500_une_panne_de_klassci_pendant_la_recherche(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(KlassciUnavailableException::upstreamFailure(503));
        });
        $compte = $this->compte($this->ecole(false), 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
        $reponse->assertJsonPath('error', 'Une erreur est survenue.');
        $reponse->assertHeaderMissing('Retry-After');
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** Le 500 du gestionnaire global, sans `error` : la spec admet les deux formes. */
    public function test_500_du_gestionnaire_global_sans_error(): void
    {
        $compte = $this->compte($this->ecole(false), 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson('/api/lms/seances/abc/activate-visio')->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('error');
    }

    /**
     * La forme réelle (#739) : la séance est dans l'emploi du temps, pas dans
     * `seances_programmees`. Même simulacre que VisioActivationSeanceSourceTest.
     */
    private function klassciConnaitLaSeance(): void
    {
        $matiere = ['id' => self::MATIERE_KLASSCI, 'nom' => 'Anglais'];
        $emploiTemps = ['data' => [[
            'id' => self::SEANCE_KLASSCI,
            'matiere' => $matiere,
            'classe' => ['id' => 4, 'libelle' => 'BTS Genie Civil'],
            'programmation' => ['date_seance' => now()->addDays(3)->format('Y-m-d')],
            'salle' => ['nom' => 'B12'],
        ]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matiere, $emploiTemps): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::JETON_KLASSCI, 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => [$matiere]]]);
            $mock->shouldReceive('requestWithUserToken')
                ->with(self::JETON_KLASSCI, 'matieres', 'GET')
                ->andReturn(['data' => [$matiere]]);
            $mock->shouldReceive('getEmploiTemps')->andReturn($emploiTemps);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }

    private function url(int $seanceId): string
    {
        return "/api/lms/seances/{$seanceId}/activate-visio";
    }

    private function ecole(bool $autonome): Institution
    {
        return $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role, ?string $jetonKlassci): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => $jetonKlassci]);
    }
}
