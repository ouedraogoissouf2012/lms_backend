<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * `GET /evaluations` (le personnel) n'avait AUCUN schéma de réponse, alors
 * qu'E3 change son enrichissement : sans catalogue KLASSCI, la classe et la
 * matière prennent leur id local, et l'enseignant est trouvé par son auteur
 * local (`EvaluationEnrichmentService`, D72). La réponse réelle suit
 * désormais `EvaluationEnrichie`, dans les deux types d'école.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class ListeDuPersonnelSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_dans_l_ecole_du_lms_les_ids_sont_locaux_et_la_reponse_suit_la_spec(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(), 'classe_nom' => '6e A',
            'matiere_id' => $maths->getKey(), 'matiere_nom' => 'Maths',
            'enseignant_id' => $enseignant->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
            'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);
        // C27 (E4) : la liste appelle encore le catalogue de KLASSCI dans une
        // école du LMS. Il ne connaît pas ces classes : les branches locales
        // présentent l'évaluation.
        $this->catalogueKlassci([], []);

        $reponse = $this->asTenant($enseignant)->getJson('/api/evaluations');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.0.klassci_classe_id', null)
            ->assertJsonPath('data.0.classe_id', $classe->getKey())
            ->assertJsonPath('data.0.classe.id', $classe->getKey())
            ->assertJsonPath('data.0.classe.nom', '6e A')
            ->assertJsonPath('data.0.matiere.id', $maths->getKey())
            ->assertJsonPath('data.0.enseignant.id', $enseignant->getKey())
            ->assertJsonPath('data.0.enseignant_nom', $enseignant->name)
            ->assertJsonPath('data.0.questions_count', 1);
        $this->assertOpenApiSchemaRefuseSans($reponse, '/evaluations', 'get', 'data');
    }

    public function test_dans_une_ecole_klassci_les_ids_sont_ceux_de_klassci_et_la_reponse_suit_la_spec(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton', 'klassci_enseignant_id' => 42])->save();
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_classe_id' => 55, 'klassci_matiere_id' => 9, 'klassci_enseignant_id' => 42,
            'classe_id' => null, 'matiere_id' => null, 'enseignant_id' => $enseignant->getKey(),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);
        $this->catalogueKlassci([['id' => 55, 'name' => 'B2 COM']], [['id' => 9, 'nom' => 'Maths']]);
        $this->disableKlassciMiddleware();

        $reponse = $this->asTenant($enseignant)->getJson('/api/evaluations');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.0.klassci_classe_id', 55)
            ->assertJsonPath('data.0.classe_id', null)
            ->assertJsonPath('data.0.classe.nom', 'B2 COM')
            ->assertJsonPath('data.0.matiere.nom', 'Maths');
    }

    public function test_un_eleve_recoit_403(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'etudiant'))->getJson('/api/evaluations');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'get');
        $reponse->assertForbidden();
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @param  list<array<string, mixed>>  $matieres
     */
    private function catalogueKlassci(array $classes, array $matieres): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classes, $matieres): void {
            $mock->shouldReceive('getClasses')->andReturn(['data' => $classes]);
            $mock->shouldReceive('getMatieres')->andReturn(['data' => $matieres]);
        });
    }
}
