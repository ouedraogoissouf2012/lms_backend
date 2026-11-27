<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1653 (PR C) — les lectures dont l'écran de modification (front#660) a besoin, en PLUS des
 * champs affichés, qui ne changent pas.
 *
 * - `GET /admin/matieres` : une matière LOCALE porte `edition` (ses valeurs brutes : le code nul
 *   reste nul, les crédits y sont). Un miroir de KLASSCI, et toute matière d'une école KLASSCI, n'en
 *   porte pas : l'absence de la clé dit « ne se modifie pas ici ».
 * - `GET /lms/classes/{id}` : chaque matière du programme porte `coefficient_classe`, lu dans
 *   `classe_matiere`, nul quand la classe n'en fixe aucun ; `coefficient` reste celui de la matière.
 *
 * Vrai jeton (`asTenant`), les deux volets.
 */
final class LesLecturesDeLEditionDuCatalogueTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    public function test_une_matiere_locale_porte_ses_valeurs_brutes_et_garde_ses_champs_affiches(): void
    {
        $ecole = $this->ecole();
        $sansCode = Matiere::factory()->create([
            'institution_id' => $ecole->id, 'klassci_id' => null, 'libelle' => 'Bureautique',
            'code' => null, 'description' => null, 'coefficient' => 2, 'credit' => 5,
        ]);

        $ligne = $this->ligne($this->asTenant($this->membre($ecole, 'superAdmin'))->getJson('/api/admin/matieres')->assertOk()->json(), $sansCode);

        self::assertSame(
            ['libelle' => 'Bureautique', 'code' => null, 'description' => null, 'coefficient' => 2, 'credit' => 5],
            $ligne['edition'],
        );
        // Les champs affichés sont INCHANGÉS : le code vide garde son N/A, rien d'autre ne bouge.
        unset($ligne['edition']);
        self::assertSame([
            'id' => $sansCode->id, 'nom' => 'Bureautique', 'code' => 'N/A', 'description' => null, 'coefficient' => 2,
            'couleur' => '#6366f1', 'heures_total' => null, 'nb_evaluations' => 0, 'nb_seances_programmees' => null,
            'nb_lecons' => 0, 'combinaisons' => [],
        ], $ligne);
    }

    public function test_un_miroir_de_klassci_dans_une_ecole_autonome_ne_porte_pas_d_edition(): void
    {
        $ecole = $this->ecole();
        $miroir = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242]);

        $ligne = $this->ligne($this->asTenant($this->membre($ecole, 'superAdmin'))->getJson('/api/admin/matieres')->assertOk()->json(), $miroir);

        self::assertArrayNotHasKey('edition', $ligne);
    }

    public function test_une_ecole_klassci_ne_recoit_aucune_edition(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $admin = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'admin', 'klassci_token' => 'fake-token']);
        $this->mock(KlassciProxyService::class, function (MockInterface $m): void {
            $m->shouldReceive('requestWithUserToken')->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 1, 'nom' => 'Maths', 'code' => 'MA', 'heures' => ['total' => 3], 'combinaisons' => []]]]);
        });

        $matieres = (array) $this->asTenant($admin)->getJson('/api/admin/matieres')->assertOk()->json('data.matieres');

        self::assertCount(1, $matieres);
        self::assertArrayNotHasKey('edition', (array) $matieres[0]);
    }

    public function test_le_programme_porte_le_coefficient_de_la_classe_a_cote_de_celui_de_la_matiere(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $fixe = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'libelle' => 'Algèbre', 'coefficient' => 2]);
        $libre = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'libelle' => 'Biologie', 'coefficient' => 3]);
        $classe->matieres()->attach($fixe->id, ['institution_id' => $ecole->id, 'coefficient' => 4]);
        $classe->matieres()->attach($libre->id, ['institution_id' => $ecole->id]);

        $programme = (array) $this->asTenant($this->membre($ecole, 'superAdmin'))
            ->getJson("/api/lms/classes/{$classe->id}")->assertOk()->json('data.matieres_disponibles');

        self::assertSame(
            [['Algèbre', 2, 4], ['Biologie', 3, null]],
            array_map(static fn (array $m): array => [$m['nom'], $m['coefficient'], $m['coefficient_classe']], $programme),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(mixed $corps, Matiere $matiere): array
    {
        $lignes = is_array($corps) ? (array) ($corps['data']['matieres'] ?? []) : [];
        foreach ($lignes as $ligne) {
            if (is_array($ligne) && ($ligne['id'] ?? null) === $matiere->id) {
                return $ligne;
            }
        }
        self::fail("Matière {$matiere->id} absente de /admin/matieres.");
    }
}
