<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;
use Throwable;

/**
 * #1609 : quand le catalogue KLASSCI est injoignable, la liste du personnel perd ce catalogue, et
 * rien d'autre. `classe` et `matiere` portent les libellés stockés, `enseignant` vient de la base
 * (#1571). Avant, la panne rendait l'évaluation brute, sans ces clés (frontend_lms#644).
 *
 * Vraie route, vrai jeton (`asTenant`), école KLASSCI, catalogue en panne simulée.
 */
final class LEnrichissementSurvitAKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{Throwable}> Les échecs de KLASSCI que lève le client HTTP. */
    public static function pannesDeKlassci(): array
    {
        return [
            'injoignable (5xx, transport, disjoncteur)' => [KlassciUnavailableException::upstreamFailure(503)],
            'refus 4xx (EchecHttpKlassci)' => [new RuntimeException('Erreur API KLASSCI: 401', 401)],
        ];
    }

    #[DataProvider('pannesDeKlassci')]
    public function test_klassci_en_panne_la_liste_garde_l_enseignant_et_les_libelles_stockes(Throwable $panne): void
    {
        $enseignant = $this->enseignantAvecSonEvaluation();
        $this->catalogueQuiLeve($panne);

        $reponse = $this->liste($enseignant);

        $reponse->assertOk()
            ->assertJsonPath('data.0.enseignant.id', 42)
            ->assertJsonPath('data.0.enseignant_nom', $enseignant->name)
            ->assertJsonPath('data.0.classe.nom', 'B2 COM')
            ->assertJsonPath('data.0.matiere.nom', 'Maths');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'get');
    }

    /** Verrou : seul un échec de KLASSCI est absorbé ; un défaut de programmation remonte. */
    public function test_un_defaut_de_programmation_dans_le_catalogue_remonte(): void
    {
        $enseignant = $this->enseignantAvecSonEvaluation();
        $this->catalogueQuiLeve(new LogicException('Défaut de programmation.'));

        $this->liste($enseignant)->assertStatus(500);
    }

    private function enseignantAvecSonEvaluation(): User
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton', 'klassci_enseignant_id' => 42])->save();
        Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_classe_id' => 55, 'klassci_matiere_id' => 9, 'klassci_enseignant_id' => 42,
            'classe_id' => null, 'matiere_id' => null, 'enseignant_id' => $enseignant->getKey(),
            'classe_nom' => 'B2 COM', 'matiere_nom' => 'Maths',
        ]);

        return $enseignant;
    }

    private function catalogueQuiLeve(Throwable $panne): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($panne): void {
            $mock->shouldReceive('getClasses')->andThrow($panne);
            $mock->shouldReceive('getMatieres')->andThrow($panne);
        });
    }

    private function liste(User $enseignant): TestResponse
    {
        return $this->asTenant($enseignant)->getJson('/api/evaluations');
    }
}
