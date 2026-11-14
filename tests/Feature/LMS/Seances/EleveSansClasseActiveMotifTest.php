<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * `GET /api/lms/seances/my-classes` : le 404 « aucune classe » dit son motif.
 *
 * Avant : un élève sans classe active recevait 404 « Aucune classe trouvée pour cet
 * étudiant » SANS motif. La même route relaie aussi les 404 de KLASSCI : le client ne
 * pouvait pas les distinguer. Le front (Fille 2) code sur
 * `reason: student_without_active_class`, posé sur CE SEUL 404.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class EleveSansClasseActiveMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const ROUTE = '/api/lms/seances/my-classes';

    public function test_ecole_du_lms_un_eleve_sans_classe_active_recoit_le_motif(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe($ecole, '6e A'), 'inactif');

        $reponse = $this->asTenant($eleve)->getJson(self::ROUTE);

        $this->assertMatchesOpenApiResponse($reponse, '/lms/seances/my-classes', 'get');
        $reponse->assertNotFound()->assertJsonPath('reason', 'student_without_active_class');
    }

    public function test_ecole_klassci_un_eleve_sans_classe_resoluble_recoit_le_motif(): void
    {
        $eleve = $this->eleveKlassci();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => null]]]);
        });

        $reponse = $this->asTenant($eleve)->getJson(self::ROUTE);

        $this->assertMatchesOpenApiResponse($reponse, '/lms/seances/my-classes', 'get');
        $reponse->assertNotFound()->assertJsonPath('reason', 'student_without_active_class');
    }

    /** Le 404 RELAYÉ de KLASSCI ne porte PAS ce motif : le client les distingue. */
    public function test_le_404_relaye_de_klassci_ne_porte_pas_le_motif(): void
    {
        $eleve = $this->eleveKlassci();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new RuntimeException('Erreur API KLASSCI: 404', 404));
        });

        $reponse = $this->asTenant($eleve)->getJson(self::ROUTE)->assertNotFound();

        self::assertNotSame('student_without_active_class', $reponse->json('reason'));
    }

    private function eleveKlassci(): User
    {
        $eleve = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $this->disableKlassciMiddleware();

        return $eleve;
    }
}
