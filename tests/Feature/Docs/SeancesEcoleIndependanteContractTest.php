<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\LocalSeanceCreator;
use App\Services\Seances\LocalSeancesListReader;
use App\Services\Seances\SeancesListReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat des trois listes de séances (lot 3d) : chaque réponse RÉELLE suit
 * ce que `docs/openapi.yaml` en dit, dans les deux types d'école.
 */
final class SeancesEcoleIndependanteContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const A_VENIR = '/lms/seances/upcoming';

    private const ENSEIGNEMENT = '/lms/seances/my-teaching';

    private const MES_CLASSES = '/lms/seances/my-classes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Carbon::setTestNow('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_200_les_trois_routes_dans_une_ecole_creee_depuis_le_lms(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $enseignant = $this->membre($ecole, 'enseignant');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        app(LocalSeanceCreator::class)->create($enseignant, [
            'titre' => 'Cours', 'date_seance' => '2026-11-12 09:30:00', 'matiere_nom' => 'Maths',
            'classe_nom' => '6e A', 'classe_id' => $classe->id,
        ]);
        app(LocalSeanceCreator::class)->create($enseignant, [
            'titre' => 'Sans classe', 'date_seance' => '2026-11-13 09:30:00',
        ]);

        $aVenir = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::A_VENIR)->assertOk();
        $enseignement = $this->asTenant($enseignant)->getJson('/api'.self::ENSEIGNEMENT)->assertOk();
        $mesClasses = $this->asTenant($eleve)->getJson('/api'.self::MES_CLASSES)->assertOk();

        $this->assertMatchesOpenApiResponse($aVenir, self::A_VENIR, 'get');
        $this->assertMatchesOpenApiResponse($enseignement, self::ENSEIGNEMENT, 'get');
        $this->assertMatchesOpenApiResponse($mesClasses, self::MES_CLASSES, 'get');
        $aVenir->assertJsonCount(2, 'data');
        $this->assertOpenApiSchemaRefuseSans($aVenir, self::A_VENIR, 'get', 'meta');
    }

    public function test_404_un_eleve_sans_classe_active(): void
    {
        $reponse = $this->asTenant($this->membre($this->ecole(), 'etudiant'))
            ->getJson('/api'.self::MES_CLASSES)
            ->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::MES_CLASSES, 'get');
    }

    public function test_500_une_panne_de_base(): void
    {
        $this->app->instance(LocalSeancesListReader::class, new class implements SeancesListReader
        {
            public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId): array
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('database is locked'));
            }

            public function getMyTeachingForUser(User $user): array
            {
                return [];
            }

            public function getMyClassesForUser(User $user): array
            {
                return [];
            }
        });

        $reponse = $this->asTenant($this->membre($this->ecole(), 'coordinateur'))
            ->getJson('/api'.self::A_VENIR)
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::A_VENIR, 'get');
    }

    // ───────────────────────── école créée depuis KLASSCI

    /** La gestion lit les séances miroirs, avec les ids KLASSCI : la spec doit les admettre. */
    public function test_200_la_gestion_d_une_ecole_creee_depuis_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        Seance::factory()->create(['institution_id' => $ecole->id, 'date_seance' => '2026-11-12 08:00:00']);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-personnel'])->save();

        $reponse = $this->asTenant($coordinateur)->getJson('/api'.self::A_VENIR)->assertOk()->assertJsonCount(1, 'data');

        $this->assertMatchesOpenApiResponse($reponse, self::A_VENIR, 'get');
    }

    public function test_401_sans_jeton_personnel_dans_une_ecole_creee_depuis_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);

        $aVenir = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::A_VENIR)->assertUnauthorized();
        $enseignement = $this->asTenant($this->membre($ecole, 'enseignant'))->getJson('/api'.self::ENSEIGNEMENT)->assertUnauthorized();
        $mesClasses = $this->asTenant($this->membre($ecole, 'etudiant'))->getJson('/api'.self::MES_CLASSES)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($aVenir, self::A_VENIR, 'get');
        $this->assertMatchesOpenApiResponse($enseignement, self::ENSEIGNEMENT, 'get');
        $this->assertMatchesOpenApiResponse($mesClasses, self::MES_CLASSES, 'get');
    }
}
