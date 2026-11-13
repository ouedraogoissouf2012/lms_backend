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
 * Même route, autre source (ADR-EI-01) : le mode de l'établissement choisit la
 * source. Une école en mode KLASSCI cherche la séance dans l'emploi du temps
 * KLASSCI, sous son identifiant KLASSCI, avec le jeton du compte : son 200, son
 * 401, son 404 et son 500 de panne KLASSCI tournent ici avec un jeton. Une école
 * en mode autonome cherche la séance sous son id local, dans l'établissement,
 * sans jeton ni appel KLASSCI : son 200, son 403 de gestion et son 404 tournent
 * avec un compte SANS jeton, comme tout compte d'une telle école (`UserFactory`
 * en donne un à tout compte), et le jeton d'un compte n'y change rien.
 *
 * Les refus de rôle tournent pour les DEUX types d'école. Vrai jeton porteur
 * (`asTenant`).
 *
 * @see app/Services/Visio/Lifecycle/VisioActivationService.php
 * @see app/Services/Visio/Lifecycle/LocalVisioActivation.php
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

    /** École KLASSCI, compte sans jeton KLASSCI : le 401 motivé de #806, et rien n'est écrit. */
    public function test_401_sans_jeton_klassci(): void
    {
        $compte = $this->compte($this->ecole(false), 'enseignant', null);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(MissingKlassciTokenException::payload());
        self::assertSame(0, Seance::query()->withoutGlobalScopes()->count());
    }

    /** École autonome : la séance locale, sous son id local, par son auteur. */
    #[DataProvider('rolesAdmis')]
    public function test_200_ecole_autonome_la_seance_locale_de_l_etablissement(string $role): void
    {
        $ecole = $this->ecole(true);
        $auteur = $this->compte($ecole, 'enseignant', null);
        $seance = $this->seanceLocale($ecole, $auteur);
        $compte = $role === 'enseignant' ? $auteur : $this->compte($ecole, $role, null);

        $reponse = $this->asTenant($compte)->postJson($this->url($seance->id))->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
        $reponse->assertJsonPath('data.classe_synced', false);
        $this->assertDatabaseHas('seances', ['id' => $seance->id, 'visio_enabled' => true, 'klassci_seance_id' => null]);
    }

    /** École autonome : un enseignant qui ne gère pas la séance reçoit le refus motivé. */
    public function test_403_ecole_autonome_un_enseignant_qui_ne_gere_pas_la_seance(): void
    {
        $ecole = $this->ecole(true);
        $seance = $this->seanceLocale($ecole, $this->compte($ecole, 'enseignant', null));

        $reponse = $this->asTenant($this->compte($ecole, 'enseignant', null))
            ->postJson($this->url($seance->id))
            ->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson([
            'success' => false,
            'message' => 'Vous ne pouvez pas gérer cette visio',
            'reason' => 'actor_not_authorized',
        ]);
        $this->assertDatabaseHas('seances', ['id' => $seance->id, 'visio_enabled' => false]);
    }

    /**
     * École autonome : l'identifiant est lu comme un id local, même par un compte
     * qui garde un jeton KLASSCI (école passée en mode autonome). Aucun appel KLASSCI.
     */
    public function test_404_ecole_autonome_une_seance_absente_de_l_etablissement(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
            $mock->shouldNotReceive('getEmploiTemps');
        });
        $compte = $this->compte($this->ecole(true), 'enseignant', self::JETON_KLASSCI);

        $reponse = $this->asTenant($compte)->postJson($this->url(self::SEANCE_KLASSCI))->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertExactJson(['success' => false, 'message' => 'Séance non trouvée']);
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
        foreach (self::typesDEcole() as $type => [$depuisLeLms]) {
            yield "étudiant, $type" => [$depuisLeLms, 'etudiant'];
            yield "admin, $type" => [$depuisLeLms, 'admin'];
            // La garde de rôle laisse passer superAdmin ; la requête le refuse.
            yield "superAdmin, $type" => [$depuisLeLms, 'superAdmin'];
        }
    }

    #[DataProvider('rolesRefuses')]
    public function test_403_un_role_non_admis(bool $depuisLeLms, string $role): void
    {
        $compte = $this->compte($this->ecole($depuisLeLms), $role, $depuisLeLms ? null : self::JETON_KLASSCI);

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

    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role, ?string $jetonKlassci): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => $jetonKlassci]);
    }

    /** Une séance locale, telle que `LocalSeanceCreator` l'écrit : aucun identifiant KLASSCI. */
    private function seanceLocale(Institution $ecole, User $auteur): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'created_by' => $auteur->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'visio_enabled' => false,
            'visio_status' => null,
            'visio_room_id' => null,
        ]);
    }
}
