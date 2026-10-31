<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Catalogue\LocalClasseMatiereLinker;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat front/serveur de « Mes matières » et de la page d'une matière
 * (école indépendante, lot 1).
 *
 * `docs/openapi.yaml` décrit les clés que le front LIT, fichier cité pour
 * chacune. Ce fichier vérifie que les DEUX sources les rendent : la source
 * locale sur des données réelles, la source KLASSCI sur la forme réelle de sa
 * charge, simulée. Une source qui perdrait une clé lue casserait l'écran en
 * 200, sans erreur ; ici, elle rougit.
 */
final class MatieresContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MES_MATIERES = '/lms/teacher/my-matieres';

    private const PAGE = '/lms/matieres/{matiereId}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── source locale

    public function test_source_locale_mes_matieres_suit_le_contrat(): void
    {
        [$enseignant] = $this->programmeLocal();

        $reponse = $this->asTenant($enseignant)->getJson('/api'.self::MES_MATIERES)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::MES_MATIERES, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::MES_MATIERES, 'get', 'data');
        self::assertSame(
            ['id', 'nom', 'coefficient', 'classes', 'statistiques'],
            array_keys((array) $reponse->json('data.0')),
            'La source locale émet exactement les clés que la carte lit.',
        );
    }

    public function test_source_locale_page_matiere_suit_le_contrat(): void
    {
        [$enseignant, $matiere] = $this->programmeLocal();

        $reponse = $this->asTenant($enseignant)->getJson('/api/lms/matieres/'.$matiere->getKey())->assertOk();

        self::assertCount(1, (array) $reponse->json('data.lessons'));
        $this->assertMatchesOpenApiResponse($reponse, self::PAGE, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::PAGE, 'get', 'data');
    }

    public function test_un_identifiant_non_numerique_suit_le_contrat(): void
    {
        [$enseignant] = $this->programmeLocal();

        $reponse = $this->asTenant($enseignant)->getJson('/api/lms/matieres/abc')->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::PAGE, 'get');
    }

    public function test_le_refus_d_un_compte_de_plateforme_suit_le_contrat(): void
    {
        $plateforme = User::factory()->create(['institution_id' => null, 'role' => 'supradmin', 'klassci_token' => null]);

        $page = $this->asTenant($plateforme)->getJson('/api/lms/matieres/1')->assertForbidden();
        $mes = $this->asTenant($plateforme)->getJson('/api'.self::MES_MATIERES)->assertForbidden();

        $this->assertMatchesOpenApiResponse($page, self::PAGE, 'get');
        $this->assertMatchesOpenApiResponse($mes, self::MES_MATIERES, 'get');
    }

    public function test_source_locale_404_suit_le_contrat(): void
    {
        [, $matiere, $ecole] = $this->programmeLocal();
        $etranger = $this->compteLocal($ecole, 'enseignant');

        $reponse = $this->asTenant($etranger)->getJson('/api/lms/matieres/'.$matiere->getKey())->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::PAGE, 'get');
    }

    // ───────────────────────── source KLASSCI

    public function test_source_klassci_mes_matieres_suit_le_contrat(): void
    {
        Queue::fake();
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $enseignant = User::factory()->teacher()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_token' => 'jeton-enseignant',
        ]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('jeton-enseignant', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => [
                    'matieres' => [['id' => 7, 'nom' => 'Anglais', 'coefficient' => 2, 'classes' => [['id' => 3, 'nom' => 'B2 COM']]]],
                    'evaluations' => [],
                ]]);
        });

        $reponse = $this->asTenant($enseignant)->getJson('/api'.self::MES_MATIERES)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::MES_MATIERES, 'get');
    }

    public function test_source_klassci_page_matiere_suit_le_contrat(): void
    {
        // Forme RÉELLE de `matieres/{id}`, reprise de
        // MatiereDetailsQueryServiceEvaluationsReuseTest : un seul appel suffit
        // au coordinateur.
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $coordinateur = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'coordinateur',
            'klassci_token' => 'jeton-coordinateur',
        ]);
        $miroir = Matiere::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 5]);
        Lesson::factory()->published()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'matiere_id' => $miroir->getKey(),
        ]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('jeton-coordinateur', 'matieres/5', 'GET')
                ->andReturn(['data' => [
                    'matiere' => ['id' => 5, 'nom' => 'Anglais', 'code' => 'ID5356'],
                    'combinaisons' => [],
                    'enseignants' => [['id' => 700, 'nom' => 'Prof Test']],
                    'seances_programmees' => [],
                    'evaluations' => [],
                ]]);
        });

        $reponse = $this->asTenant($coordinateur)->getJson('/api/lms/matieres/5')->assertOk();

        self::assertCount(1, (array) $reponse->json('data.lessons'));
        $this->assertMatchesOpenApiResponse($reponse, self::PAGE, 'get');
    }

    public function test_source_klassci_sans_jeton_suit_le_contrat(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->getKey(), 'klassci_token' => null]);

        $reponse = $this->asTenant($enseignant)->getJson('/api'.self::MES_MATIERES)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');

        $this->assertMatchesOpenApiResponse($reponse, self::MES_MATIERES, 'get');
    }

    // ───────────────────────── harnais

    /**
     * @return array{0: User, 1: Matiere, 2: Institution}
     */
    private function programmeLocal(): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $enseignant = $this->compteLocal($ecole, 'enseignant');
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null, 'libelle' => '6e A']);
        $matiere = Matiere::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null, 'libelle' => 'Maths']);

        $tenants = app(TenantManager::class);
        $tenants->set($ecole);
        app(LocalClasseMatiereLinker::class)->rattacher((int) $classe->getKey(), (int) $matiere->getKey(), (int) $enseignant->getKey());
        $tenants->reset();
        // Une leçon RÉELLE : un contrat éprouvé sur une liste vide ne prouve
        // rien des clés d'une leçon (revue du lot 1).
        Lesson::factory()->published()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'enseignant_id' => $enseignant->getKey(),
        ]);

        return [$enseignant, $matiere, $ecole];
    }

    /**
     * Un compte LOCAL, comme ceux d'une école indépendante : la fabrique pose
     * par défaut un jeton et une identité KLASSCI, et un 404 obtenu par la
     * source KLASSCI passerait alors pour celui de la source locale.
     */
    private function compteLocal(Institution $ecole, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => $role,
            'klassci_token' => null,
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);
    }
}
