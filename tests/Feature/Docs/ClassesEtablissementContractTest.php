<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\InstitutionClassesReader;
use App\Services\Classe\LocalInstitutionClassesReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `GET /proxy/classes` (lot 3a) : chaque réponse RÉELLE suit ce que
 * `docs/openapi.yaml` en dit, dans les deux types d'école.
 */
final class ClassesEtablissementContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/proxy/classes';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_200_la_source_locale_d_une_ecole_creee_depuis_le_lms(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->programme($classe, $this->matiere($ecole, 'Maths'), $this->membre($ecole, 'enseignant'));
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::CHEMIN)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $reponse->assertJsonPath('data.0.id', $classe->id)->assertJsonPath('data.0.places_occupees', 1);
    }

    /** UN `Http::fake` : KLASSCI répond 503, la route rend le 503 canonique avec `Retry-After`. */
    public function test_503_klassci_indisponible_dans_une_ecole_creee_depuis_klassci(): void
    {
        Http::fake(static fn () => Http::response(['message' => 'panne'], 503));
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson('/api'.self::CHEMIN)
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) KlassciUnavailableException::retryAfterSeconds());

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_500_une_panne_de_base(): void
    {
        $this->app->instance(LocalInstitutionClassesReader::class, new class implements InstitutionClassesReader
        {
            public function list(User $user, array $filtres): array
            {
                throw new QueryException('sqlite', 'select 1', [], new PDOException('database is locked'));
            }
        });

        $reponse = $this->asTenant($this->membre($this->ecole(), 'coordinateur'))
            ->getJson('/api'.self::CHEMIN)
            ->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'message');
    }
}
