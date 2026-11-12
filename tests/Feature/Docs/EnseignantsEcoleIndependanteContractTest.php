<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enseignant\InstitutionTeachersReader;
use App\Services\Enseignant\LocalInstitutionTeachersReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /proxy/enseignants` et `GET /lms/enseignants` (lot 3b) :
 * chaque réponse RÉELLE suit ce que `docs/openapi.yaml` en dit, dans les deux
 * types d'école.
 *
 * Un seul `Http::fake` par méthode : deux s'empilent, et le premier gagne.
 */
final class EnseignantsEcoleIndependanteContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ANNUAIRE = '/proxy/enseignants';

    private const LISTE = '/lms/enseignants';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_200_les_deux_routes_dans_une_ecole_creee_depuis_le_lms(): void
    {
        $ecole = $this->ecole();
        $awa = $this->membre($ecole, 'enseignant');
        $this->programme($this->classe($ecole, '6e A'), $this->matiere($ecole, 'Maths'), $awa);
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $annuaire = $this->asTenant($coordinateur)->getJson('/api'.self::ANNUAIRE)->assertOk();
        $liste = $this->asTenant($coordinateur)->getJson('/api'.self::LISTE)->assertOk();
        $details = $this->asTenant($coordinateur)->getJson('/api'.self::LISTE.'?with_details=true')->assertOk();

        $this->assertMatchesOpenApiResponse($annuaire, self::ANNUAIRE, 'get');
        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
        $this->assertMatchesOpenApiResponse($details, self::LISTE, 'get');
        $details->assertJsonPath('data.0.id', $awa->id)->assertJsonPath('data.0.matieres.0.nom', 'Maths');
        $this->assertOpenApiSchemaRefuseSans($liste, self::LISTE, 'get', 'meta');
    }

    /** Hors de la gestion et de l'enseignant : la liste vide d'aujourd'hui, sans lecture. */
    public function test_200_un_eleve_recoit_une_liste_vide(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');

        $annuaire = $this->asTenant($eleve)->getJson('/api'.self::ANNUAIRE)->assertOk()->assertJsonPath('data', []);
        $liste = $this->asTenant($eleve)->getJson('/api'.self::LISTE)->assertOk()->assertJsonPath('data', []);

        $this->assertMatchesOpenApiResponse($annuaire, self::ANNUAIRE, 'get');
        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
    }

    public function test_500_une_panne_de_base_des_deux_routes(): void
    {
        $this->app->instance(LocalInstitutionTeachersReader::class, new class implements InstitutionTeachersReader
        {
            public function annuaire(User $lecteur): array
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('database is locked'));
            }

            public function listeEnrichie(User $lecteur, bool $details): array
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('database is locked'));
            }
        });
        $coordinateur = $this->membre($this->ecole(), 'coordinateur');

        $annuaire = $this->asTenant($coordinateur)->getJson('/api'.self::ANNUAIRE)->assertStatus(500);
        $liste = $this->asTenant($coordinateur)->getJson('/api'.self::LISTE)->assertStatus(500);

        $this->assertMatchesOpenApiResponse($annuaire, self::ANNUAIRE, 'get');
        $this->assertMatchesOpenApiResponse($liste, self::LISTE, 'get');
    }

    // ───────────────────────── école créée depuis KLASSCI

    /**
     * UN `Http::fake` : KLASSCI rend un enseignant dont l'id est une chaîne, avec
     * des clés que le LMS ne tient pas. La route le relaie : la spec doit
     * l'admettre.
     */
    public function test_200_l_annuaire_dans_une_ecole_creee_depuis_klassci(): void
    {
        Http::fake(static fn () => Http::response(['success' => true, 'data' => [
            ['id' => '77', 'nom' => 'Diallo', 'prenom' => 'Bakary', 'teacher_id' => 77, 'statistiques' => ['nb_classes' => 3]],
        ]], 200));
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::ANNUAIRE)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::ANNUAIRE, 'get');
        $reponse->assertJsonPath('data.0.id', '77');
    }
}
