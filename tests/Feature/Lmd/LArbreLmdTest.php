<?php

declare(strict_types=1);

namespace Tests\Feature\Lmd;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1654 (PR A) — l'arbre LMD d'une école autonome : domaines → mentions → parcours.
 *
 * Chaque niveau se crée, se modifie, se désactive (`actif`), sans jamais se supprimer ; l'arbre se lit d'un bloc,
 * trié par libellé (`?actifs=1` n'en garde que les éléments actifs). Le code est facultatif ; donné, il est unique
 * PAR NIVEAU et par école, à la casse et aux espaces près (la base tranche) : 422 `lmd_code_already_used`. Un parent
 * d'une autre école : 422 sous son champ ; un élément d'une autre école : 404.
 *
 * Volet KLASSCI : KLASSCI n'expose aucune structure LMD au LMS → 403 `catalogue_managed_by_klassci`, en lecture
 * comme en écriture. Vrai jeton.
 */
final class LArbreLmdTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_l_arbre_se_construit_et_se_lit_d_un_bloc(): void
    {
        [, $gestion] = $this->ecole();
        $sciences = $this->creer($gestion, 'domaines', ['libelle' => 'Sciences et technologies', 'code' => 'ST'])->json('data.id');
        $this->creer($gestion, 'domaines', ['libelle' => 'Droit'])->assertCreated();
        $info = $this->creer($gestion, 'mentions', ['libelle' => 'Informatique', 'domaine_id' => $sciences])->assertCreated()->json('data.id');
        $this->creer($gestion, 'parcours', ['libelle' => 'Génie logiciel', 'code' => 'GL', 'mention_id' => $info])->assertCreated()
            ->assertJsonPath('data.libelle', 'Génie logiciel')->assertJsonPath('data.actif', true);

        $arbre = $this->asTenant($gestion)->getJson('/api/lmd/arbre')->assertOk()->json('data');

        self::assertSame(['Droit', 'Sciences et technologies'], array_column($arbre, 'libelle'));
        self::assertSame('Informatique', $arbre[1]['mentions'][0]['libelle']);
        self::assertSame('GL', $arbre[1]['mentions'][0]['parcours'][0]['code']);
        self::assertSame([], $arbre[0]['mentions']);
    }

    public function test_un_element_se_modifie_et_se_desactive_sans_disparaitre(): void
    {
        [, $gestion] = $this->ecole();
        $domaine = $this->creer($gestion, 'domaines', ['libelle' => 'Sciences'])->json('data.id');
        $mention = $this->creer($gestion, 'mentions', ['libelle' => 'Maths', 'domaine_id' => $domaine])->json('data.id');

        $this->asTenant($gestion)->patchJson("/api/lmd/mentions/{$mention}", ['libelle' => 'Mathématiques', 'actif' => false])->assertOk()
            ->assertJsonPath('data.libelle', 'Mathématiques')->assertJsonPath('data.actif', false);

        $tout = $this->asTenant($gestion)->getJson('/api/lmd/arbre')->assertOk()->json('data.0.mentions');
        self::assertSame([['Mathématiques', false]], array_map(static fn (array $m): array => [$m['libelle'], $m['actif']], $tout));
        $this->asTenant($gestion)->getJson('/api/lmd/arbre?actifs=1')->assertOk()->assertJsonPath('data.0.mentions', []);
        self::assertSame(1, DB::table('lmd_mentions')->count());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function niveaux(): array
    {
        return ['domaine' => ['domaines', []], 'mention' => ['mentions', ['domaine_id' => 'D']], 'parcours' => ['parcours', ['mention_id' => 'M']]];
    }

    #[DataProvider('niveaux')]
    public function test_un_code_est_unique_par_niveau_et_par_ecole(string $niveau, array $parent): void
    {
        [, $gestion] = $this->ecole();
        $corps = $this->avecParents($gestion, $parent);
        $this->creer($gestion, $niveau, ['libelle' => 'Un', 'code' => 'ABC'] + $corps)->assertCreated();

        $this->creer($gestion, $niveau, ['libelle' => 'Deux', 'code' => ' abc '] + $corps)
            ->assertStatus(422)->assertJsonValidationErrors(['code'])->assertJsonPath('reason', 'lmd_code_already_used');
        $this->creer($gestion, $niveau, ['libelle' => 'Sans code'] + $corps)->assertCreated();
        $this->creer($gestion, $niveau, ['libelle' => 'Sans code non plus'] + $corps)->assertCreated();
    }

    /** Le même code à deux niveaux différents n'est pas un doublon : l'unicité est PAR niveau. */
    public function test_le_meme_code_a_deux_niveaux_n_est_pas_un_doublon(): void
    {
        [, $gestion] = $this->ecole();
        $domaine = $this->creer($gestion, 'domaines', ['libelle' => 'Info', 'code' => 'INFO'])->json('data.id');

        $this->creer($gestion, 'mentions', ['libelle' => 'Info', 'code' => 'INFO', 'domaine_id' => $domaine])->assertCreated();
    }

    public function test_un_parent_ou_un_element_d_une_autre_ecole_est_refuse(): void
    {
        [, $gestion] = $this->ecole();
        [, $voisin] = $this->ecole();
        $domaineVoisin = $this->creer($voisin, 'domaines', ['libelle' => 'Voisin'])->json('data.id');

        $this->creer($gestion, 'mentions', ['libelle' => 'Intrusion', 'domaine_id' => $domaineVoisin])
            ->assertStatus(422)->assertJsonValidationErrors(['domaine_id']);
        $this->asTenant($gestion)->patchJson("/api/lmd/domaines/{$domaineVoisin}", ['libelle' => 'Intrusion'])->assertNotFound();
        $this->asTenant($gestion)->getJson('/api/lmd/arbre')->assertOk()->assertJsonPath('data', []);
        self::assertSame('Voisin', DB::table('lmd_domaines')->where('id', $domaineVoisin)->value('libelle'));
    }

    public function test_un_libelle_ou_un_parent_manquant_est_refuse(): void
    {
        [, $gestion] = $this->ecole();

        $this->creer($gestion, 'domaines', ['libelle' => ' '])->assertStatus(422)->assertJsonValidationErrors(['libelle']);
        $this->creer($gestion, 'mentions', ['libelle' => 'Orpheline'])->assertStatus(422)->assertJsonValidationErrors(['domaine_id']);
        $this->creer($gestion, 'parcours', ['libelle' => 'Orphelin'])->assertStatus(422)->assertJsonValidationErrors(['mention_id']);
    }

    public function test_une_ecole_klassci_est_refusee_en_lecture_comme_en_ecriture(): void
    {
        [, $gestion] = $this->ecole(InstitutionMode::Klassci);

        $this->asTenant($gestion)->getJson('/api/lmd/arbre')->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->creer($gestion, 'domaines', ['libelle' => 'Sciences'])->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame(0, DB::table('lmd_domaines')->count());
    }

    private function creer(User $gestion, string $niveau, array $corps): TestResponse
    {
        return $this->asTenant($gestion)->postJson("/api/lmd/{$niveau}", $corps);
    }

    /**
     * @param  array<string, string>  $parent
     * @return array<string, int>
     */
    private function avecParents(User $gestion, array $parent): array
    {
        if ($parent === []) {
            return [];
        }
        $domaine = (int) $this->creer($gestion, 'domaines', ['libelle' => 'Parent'])->json('data.id');
        if (isset($parent['domaine_id'])) {
            return ['domaine_id' => $domaine];
        }

        return ['mention_id' => (int) $this->creer($gestion, 'mentions', ['libelle' => 'Parent', 'domaine_id' => $domaine])->json('data.id')];
    }

    /** @return array{0: Institution, 1: User} */
    private function ecole(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur', 'klassci_token' => null])];
    }
}
