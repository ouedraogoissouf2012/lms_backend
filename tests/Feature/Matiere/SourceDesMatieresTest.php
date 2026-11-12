<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * La ligne dit d'où elle vient (D172). Dans une école créée depuis le LMS, chaque
 * carte de matière des deux routes porte `source: lms_local` : ses ids sont
 * LOCAUX, à envoyer tels quels sous `classe_id` ou `matiere_id`. Dans une école
 * créée depuis KLASSCI, la clé est absente : rien ne change. Aucun mode d'école
 * n'est exposé (D132 Q1).
 */
final class SourceDesMatieresTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MES_MATIERES = '/lms/teacher/my-matieres';

    private const MATIERES = '/proxy/matieres';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_chaque_carte_locale_des_deux_routes_porte_sa_source(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $this->programme($classe, $this->matiere($ecole, 'Maths'), $enseignant);
        $this->programme($classe, $this->matiere($ecole, 'SVT'), $enseignant);

        $mesMatieres = $this->asTenant($enseignant)->getJson('/api'.self::MES_MATIERES)->assertOk();
        $matieres = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::MATIERES)->assertOk();

        self::assertSame(['lms_local', 'lms_local'], array_column((array) $mesMatieres->json('data'), 'source'));
        self::assertSame(['lms_local', 'lms_local'], array_column((array) $matieres->json('data'), 'source'));
        $this->assertMatchesOpenApiResponse($mesMatieres, self::MES_MATIERES, 'get');
        $this->assertMatchesOpenApiResponse($matieres, self::MATIERES, 'get');
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_aucune_carte_de_klassci_ne_porte_de_source(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('jeton-personnel', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => [
                    'matieres' => [['id' => 7, 'nom' => 'Anglais', 'coefficient' => 2, 'classes' => [['id' => 3, 'nom' => 'B2 COM']]]],
                    'evaluations' => [],
                ]]);
            $mock->shouldReceive('getMatieres')
                ->once()
                ->andReturn(['success' => true, 'data' => [['id' => 12, 'nom' => 'Comptabilité', 'code' => 'CPT']]]);
        });
        $ecole = $this->ecole(InstitutionMode::Klassci);

        $mesMatieres = $this->asTenant($this->porteur($ecole, 'enseignant'))->getJson('/api'.self::MES_MATIERES)->assertOk();
        $matieres = $this->asTenant($this->porteur($ecole, 'coordinateur'))->getJson('/api'.self::MATIERES)->assertOk();

        $this->assertSansSource($mesMatieres);
        $this->assertSansSource($matieres);
        $this->assertMatchesOpenApiResponse($mesMatieres, self::MES_MATIERES, 'get');
        $this->assertMatchesOpenApiResponse($matieres, self::MATIERES, 'get');
    }

    // ───────────────────────── harnais

    private function porteur(Institution $ecole, string $role): User
    {
        $compte = $this->membre($ecole, $role);
        $compte->forceFill(['klassci_token' => 'jeton-personnel'])->save();

        return $compte;
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertSansSource(TestResponse $reponse): void
    {
        $cartes = (array) $reponse->json('data');
        self::assertNotSame([], $cartes);
        foreach ($cartes as $carte) {
            self::assertIsArray($carte);
            self::assertArrayNotHasKey('source', $carte);
        }
    }
}
