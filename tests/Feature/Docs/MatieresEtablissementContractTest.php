<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Matiere\InstitutionMatieresReader;
use App\Services\Matiere\LocalInstitutionMatieresReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /proxy/matieres`, `GET /admin/matieres` et
 * `GET /proxy/structure` (lot 3c) : chaque réponse RÉELLE suit ce que
 * `docs/openapi.yaml` en dit, dans les deux types d'école.
 *
 * Un seul `Http::fake` par méthode : deux s'empilent, et le premier gagne.
 */
final class MatieresEtablissementContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const LISTE = '/proxy/matieres';

    private const ADMIN = '/admin/matieres';

    private const STRUCTURE = '/proxy/structure';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_200_les_trois_routes_dans_une_ecole_creee_depuis_le_lms(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $this->membre($ecole, 'enseignant'));
        $this->matiere($ecole, 'Musique');
        $this->lecon($classe, $maths, $this->membre($ecole, 'enseignant'), 'draft');
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $liste = $this->asTenant($coordinateur)->getJson('/api'.self::LISTE)->assertOk();
        $admin = $this->asTenant($coordinateur)->getJson('/api'.self::ADMIN)->assertOk();
        $structure = $this->asTenant($coordinateur)->getJson('/api'.self::STRUCTURE)->assertOk();

        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
        $this->assertMatchesOpenApiResponse($admin, self::ADMIN, 'get');
        $this->assertMatchesOpenApiResponse($structure, self::STRUCTURE, 'get');
        $liste->assertJsonPath('data.0.classes.0.id', $classe->id);
        $admin->assertJsonPath('data.matieres.0.nb_lecons', 1);
    }

    public function test_403_l_administration_refuse_un_enseignant(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'enseignant'))->getJson('/api'.self::ADMIN)->assertForbidden();

        $this->assertMatchesOpenApiResponse($reponse, self::ADMIN, 'get');
    }

    public function test_500_une_panne_de_base_de_l_administration(): void
    {
        $this->app->instance(LocalInstitutionMatieresReader::class, new class implements InstitutionMatieresReader
        {
            public function list(User $user, array $filtres): array
            {
                return [];
            }

            public function listForAdmin(User $user): array
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('database is locked'));
            }
        });

        $reponse = $this->asTenant($this->membre($this->ecole(), 'coordinateur'))
            ->getJson('/api'.self::ADMIN)
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::ADMIN, 'get');
    }

    // ───────────────────────── école créée depuis KLASSCI

    /**
     * UN `Http::fake` : KLASSCI rend une matière dont l'id est une chaîne et le
     * coefficient une chaîne, et sa structure. Les deux routes relaient, la
     * troisième projette : la spec doit admettre les trois.
     */
    public function test_200_les_trois_routes_dans_une_ecole_creee_depuis_klassci(): void
    {
        $matiere = [
            'id' => '12',
            'nom' => 'Comptabilité',
            'code' => 'CPT',
            'coefficient' => '2',
            'couleur' => '#10b981',
            'heures' => ['total' => 40],
            'lms_metadata' => ['total_evaluations' => 3],
            'combinaisons' => [['filiere' => ['id' => 4, 'nom' => 'Commerce'], 'niveau' => ['id' => 2, 'nom' => 'B2']]],
        ];
        Http::fake(static fn ($requete) => str_ends_with(parse_url($requete->url(), PHP_URL_PATH) ?: '', '/structure')
            ? Http::response(['success' => true, 'data' => ['filieres' => [['id' => 4]], 'niveaux_etudes' => [['id' => 2]]]], 200)
            : Http::response(['success' => true, 'data' => [$matiere]], 200));
        $porteur = $this->membre($this->ecoleKlassci(), 'coordinateur');
        $porteur->forceFill(['klassci_token' => 'jeton-personnel'])->save();

        $liste = $this->asTenant($porteur)->getJson('/api'.self::LISTE)->assertOk();
        $admin = $this->asTenant($porteur)->getJson('/api'.self::ADMIN)->assertOk();
        $structure = $this->asTenant($porteur)->getJson('/api'.self::STRUCTURE)->assertOk();

        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
        $this->assertMatchesOpenApiResponse($admin, self::ADMIN, 'get');
        $this->assertMatchesOpenApiResponse($structure, self::STRUCTURE, 'get');
        $admin->assertJsonPath('data.matieres.0.heures_total', 40);
    }

    public function test_401_sans_jeton_personnel_dans_une_ecole_creee_depuis_klassci(): void
    {
        Http::fake(static fn () => Http::response(['success' => true, 'data' => []], 200));
        $sansJeton = $this->membre($this->ecoleKlassci(), 'coordinateur');

        $liste = $this->asTenant($sansJeton)->getJson('/api'.self::LISTE)->assertUnauthorized();
        $admin = $this->asTenant($sansJeton)->getJson('/api'.self::ADMIN)->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
        $this->assertMatchesOpenApiResponse($admin, self::ADMIN, 'get');
        Http::assertNothingSent();
    }

    private function ecoleKlassci(): Institution
    {
        return Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);
    }
}
