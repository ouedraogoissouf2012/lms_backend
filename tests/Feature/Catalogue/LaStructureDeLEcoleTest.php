<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1648 (PR A) — une école autonome crée, modifie, liste et supprime ses filières et ses niveaux d'études.
 *
 * Les lectures gardent la forme du volet KLASSCI, `{id, nom, code}` (et `ordre` pour un niveau, qui trie la
 * liste) : `GET /proxy/structure`, `/proxy/filieres`, `/proxy/niveaux-etudes`. Ces deux dernières appelaient
 * KLASSCI même dans une école autonome, qui n'en a pas : une panne, corrigée ici. Le code est unique dans
 * l'école, à la casse et aux espaces près (la base tranche) : 422 `field_code_already_used` /
 * `level_code_already_used`.
 *
 * Deux volets : école KLASSCI → écriture refusée (403 `catalogue_managed_by_klassci`), lectures relayées
 * comme avant. Une filière d'une autre école : 404, et elle ne sort dans aucune liste. Vrai jeton.
 */
final class LaStructureDeLEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function elements(): array
    {
        return [
            'filière' => ['/api/filieres', '/api/proxy/filieres', 'filieres'],
            'niveau' => ['/api/niveaux-etudes', '/api/proxy/niveaux-etudes', 'niveaux_etudes'],
        ];
    }

    #[DataProvider('elements')]
    public function test_un_element_se_cree_se_modifie_et_se_lit_dans_la_forme_klassci(string $url, string $proxy, string $cle): void
    {
        Http::fake();
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'coordinateur');

        $id = $this->asTenant($gestion)->postJson($url, ['libelle' => 'Commerce', 'code' => ' com '])
            ->assertCreated()->assertJsonPath('data.nom', 'Commerce')->assertJsonPath('data.code', 'com')->json('data.id');
        $this->asTenant($gestion)->patchJson("{$url}/{$id}", ['libelle' => 'Commerce international'])
            ->assertOk()->assertJsonPath('data.nom', 'Commerce international')->assertJsonPath('data.code', 'com');

        $this->asTenant($gestion)->getJson($proxy)->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.nom', 'Commerce international')->assertJsonPath('data.0.code', 'com');
        $this->asTenant($gestion)->getJson('/api/proxy/structure')->assertOk()->assertJsonPath("data.{$cle}.0.id", $id);
        Http::assertNothingSent();
    }

    #[DataProvider('elements')]
    public function test_un_code_deja_pris_dans_l_ecole_est_refuse_ailleurs_non(string $url): void
    {
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'coordinateur');
        $this->asTenant($gestion)->postJson($url, ['libelle' => 'Commerce', 'code' => 'COM'])->assertCreated();

        $reason = $url === '/api/filieres' ? 'field_code_already_used' : 'level_code_already_used';
        $this->asTenant($gestion)->postJson($url, ['libelle' => 'Autre', 'code' => ' com '])
            ->assertStatus(422)->assertJsonValidationErrors(['code'])->assertJsonPath('reason', $reason);
        $this->asTenant($this->membre($this->ecole(), 'coordinateur'))->postJson($url, ['libelle' => 'Commerce', 'code' => 'COM'])->assertCreated();
    }

    #[DataProvider('elements')]
    public function test_un_element_inemploye_se_supprime(string $url, string $proxy): void
    {
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'coordinateur');
        $id = $this->asTenant($gestion)->postJson($url, ['libelle' => 'Commerce'])->assertCreated()->json('data.id');

        $this->asTenant($gestion)->deleteJson("{$url}/{$id}")->assertOk();
        $this->asTenant($gestion)->getJson($proxy)->assertOk()->assertJsonPath('data', []);
    }

    #[DataProvider('elements')]
    public function test_l_element_d_une_autre_ecole_est_introuvable_et_ne_sort_nulle_part(string $url, string $proxy): void
    {
        $ailleurs = $this->ecole();
        $id = $this->asTenant($this->membre($ailleurs, 'coordinateur'))->postJson($url, ['libelle' => 'Voisine'])->assertCreated()->json('data.id');
        $gestion = $this->membre($this->ecole(), 'coordinateur');

        $this->asTenant($gestion)->patchJson("{$url}/{$id}", ['libelle' => 'Intrusion'])->assertNotFound();
        $this->asTenant($gestion)->deleteJson("{$url}/{$id}")->assertNotFound();
        $this->asTenant($gestion)->getJson($proxy)->assertOk()->assertJsonPath('data', []);
        self::assertSame('Voisine', DB::table($url === '/api/filieres' ? 'filieres' : 'niveaux_etudes')->where('id', $id)->value('libelle'));
    }

    public function test_les_niveaux_se_lisent_dans_leur_ordre(): void
    {
        $gestion = $this->membre($this->ecole(), 'coordinateur');
        foreach ([['L3', 3], ['L1', 1], ['L2', 2]] as [$libelle, $ordre]) {
            $this->asTenant($gestion)->postJson('/api/niveaux-etudes', ['libelle' => $libelle, 'ordre' => $ordre])->assertCreated();
        }

        $noms = array_column((array) $this->asTenant($gestion)->getJson('/api/proxy/niveaux-etudes')->json('data'), 'nom');
        self::assertSame(['L1', 'L2', 'L3'], $noms);
    }

    #[DataProvider('elements')]
    public function test_un_libelle_vide_est_refuse(string $url): void
    {
        $this->asTenant($this->membre($this->ecole(), 'coordinateur'))->postJson($url, ['libelle' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors(['libelle']);
    }

    #[DataProvider('elements')]
    public function test_une_ecole_klassci_n_ecrit_pas_et_lit_toujours_klassci(string $url, string $proxy): void
    {
        $methode = $url === '/api/filieres' ? 'getFilieres' : 'getNiveauxEtudes';
        $rendu = ['success' => true, 'data' => [['id' => 4, 'nom' => 'Commerce']]];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($methode, $rendu): void {
            $mock->shouldReceive($methode)->once()->andReturn($rendu);
        });
        $gestion = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');

        $this->asTenant($gestion)->postJson($url, ['libelle' => 'Commerce'])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->asTenant($gestion)->getJson($proxy)->assertOk()->assertExactJson($rendu);
    }

    /** Témoin : une école sans filière lit des listes vides, sans appeler KLASSCI. */
    public function test_une_ecole_autonome_sans_structure_lit_des_listes_vides(): void
    {
        Http::fake();
        $gestion = $this->membre($this->ecole(), 'coordinateur');

        $this->asTenant($gestion)->getJson('/api/proxy/filieres')->assertOk()->assertExactJson(['success' => true, 'data' => []]);
        $this->asTenant($gestion)->getJson('/api/proxy/niveaux-etudes')->assertOk()->assertExactJson(['success' => true, 'data' => []]);
        Http::assertNothingSent();
    }
}
