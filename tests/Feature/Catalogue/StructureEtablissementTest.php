<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * `GET /api/proxy/structure` — les filières et les niveaux d'études de
 * l'établissement (lot 3c, ADR-EI-01).
 *
 * Dans une école créée depuis le LMS, la route rendait 503 : AdminMatieres, qui
 * la charge avec ses matières, ne s'affichait pas. Aucune filière ni aucun
 * niveau n'y existe : elle rend des listes vides. Dans une école créée depuis
 * KLASSCI, elle relaie KLASSCI, inchangée.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class StructureEtablissementTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const STRUCTURE = '/api/proxy/structure';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_l_ecole_creee_depuis_le_lms_a_une_structure_vide_sans_rien_emettre(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['filieres' => [['id' => 1]]]], 200)]);
        $ecole = $this->ecole();

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::STRUCTURE)
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['filieres' => [], 'niveaux_etudes' => []]]);

        Http::assertNothingSent();
    }

    public function test_l_ecole_creee_depuis_klassci_recoit_ce_que_rend_klassci(): void
    {
        $rendu = ['success' => true, 'data' => ['filieres' => [['id' => 4, 'nom' => 'Commerce']], 'niveaux_etudes' => [['id' => 2]]]];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($rendu): void {
            $mock->shouldReceive('getStructure')->once()->andReturn($rendu);
        });

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))
            ->getJson(self::STRUCTURE)
            ->assertOk()
            ->assertExactJson($rendu);
    }

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getStructure')->once()->andReturn(['success' => true, 'data' => ['filieres' => [['id' => 4]]]]);
        });

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))
            ->getJson(self::STRUCTURE)
            ->assertJsonPath('data.filieres.0.id', 4);
        $this->asTenant($this->membre($this->ecole(), 'coordinateur'))
            ->getJson(self::STRUCTURE)
            ->assertJsonPath('data.filieres', []);
    }
}
