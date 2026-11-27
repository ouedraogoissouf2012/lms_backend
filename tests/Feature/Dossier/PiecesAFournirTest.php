<?php

declare(strict_types=1);

namespace Tests\Feature\Dossier;

use App\Enums\InstitutionMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1661, PR 1 : le catalogue des pièces à fournir d'une école autonome. Sous vrai jeton.
 *
 * - `GET`, `POST /api/pieces-a-fournir`, `PUT /api/pieces-a-fournir/{piece}` : gestion d'une école autonome.
 * - Une pièce : `code` (unique DANS l'école, celui qu'attend une réserve d'inscription), libellé, consigne, forme
 *   (original ou copie), quantité, portée (une fois ou à chaque inscription), validité en jours (ou aucune),
 *   obligatoire, active. Elle se DÉSACTIVE, elle ne se supprime pas.
 * - Elle vaut pour toute l'école ; le ciblage par filière et niveau attend #1648 (hors lot).
 * - École KLASSCI : 403 `catalogue_managed_by_klassci`. Autre école : 404. Audit.
 */
final class PiecesAFournirTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const PIECE = [
        'code' => 'extrait_naissance', 'libelle' => 'Extrait d\'acte de naissance', 'consigne' => 'Original de moins de trois mois.',
        'forme' => 'original', 'quantite' => 1, 'portee' => 'une_fois', 'validite_jours' => 90, 'obligatoire' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_la_gestion_cree_puis_liste_les_pieces(): void
    {
        $admin = $this->membre($this->ecole(), 'admin');

        $creee = $this->creer($admin, self::PIECE);

        $creee->assertCreated()->assertJsonPath('data.code', 'extrait_naissance')->assertJsonPath('data.actif', true)
            ->assertJsonPath('data.validite_jours', 90);
        $this->assertMatchesOpenApiResponse($creee, '/pieces-a-fournir', 'post');
        $this->creer($admin, ['code' => 'photo', 'libelle' => 'Photo d\'identité', 'forme' => 'copie', 'quantite' => 2, 'portee' => 'chaque_inscription', 'validite_jours' => null, 'obligatoire' => false])->assertCreated();

        $liste = $this->asTenant($admin)->getJson('/api/pieces-a-fournir');
        $liste->assertOk()->assertJsonCount(2, 'data');
        $this->assertMatchesOpenApiResponse($liste, '/pieces-a-fournir', 'get');
        self::assertSame(['Extrait d\'acte de naissance', 'Photo d\'identité'], array_column((array) $liste->json('data'), 'libelle'), 'Triées par libellé.');
        self::assertTrue(DB::table('audit_logs')->where('auditable_type', 'like', '%PieceAFournir%')->exists());
    }

    public function test_le_code_est_unique_dans_l_ecole_seulement(): void
    {
        $admin = $this->membre($this->ecole(), 'admin');
        $this->creer($admin, self::PIECE)->assertCreated();

        $this->creer($admin, self::PIECE)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->creer($this->membre($this->ecole(), 'admin'), self::PIECE)->assertCreated();
    }

    public function test_modifier_et_desactiver_une_piece(): void
    {
        $admin = $this->membre($this->ecole(), 'admin');
        $id = $this->creer($admin, self::PIECE)->json('data.id');

        $reponse = $this->asTenant($admin)->putJson("/api/pieces-a-fournir/{$id}", ['libelle' => 'Extrait de naissance', 'actif' => false]);

        $reponse->assertOk()->assertJsonPath('data.libelle', 'Extrait de naissance')->assertJsonPath('data.actif', false)
            ->assertJsonPath('data.code', 'extrait_naissance');
        $this->assertMatchesOpenApiResponse($reponse, '/pieces-a-fournir/{piece}', 'put');
        $this->asTenant($admin)->getJson('/api/pieces-a-fournir?actif=1')->assertJsonCount(0, 'data');
        $this->asTenant($admin)->getJson('/api/pieces-a-fournir')->assertJsonCount(1, 'data');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'code absent' => [['code' => null] + self::PIECE, 'code'],
            'code mal formé' => [['code' => 'Extrait Naissance!'] + self::PIECE, 'code'],
            'forme inconnue' => [['forme' => 'scan'] + self::PIECE, 'forme'],
            'portée inconnue' => [['portee' => 'parfois'] + self::PIECE, 'portee'],
            'quantité nulle' => [['quantite' => 0] + self::PIECE, 'quantite'],
            'validité nulle' => [['validite_jours' => 0] + self::PIECE, 'validite_jours'],
            'libellé absent' => [['libelle' => ''] + self::PIECE, 'libelle'],
        ];
    }

    /** @param  array<string, mixed>  $corps */
    #[DataProvider('saisiesRefusees')]
    public function test_422_une_saisie_refusee_n_ecrit_rien(array $corps, string $champ): void
    {
        $admin = $this->membre($this->ecole(), 'admin');

        $reponse = $this->creer($admin, $corps);

        $reponse->assertUnprocessable()->assertJsonValidationErrors($champ);
        $this->assertMatchesOpenApiResponse($reponse, '/pieces-a-fournir', 'post');
        self::assertSame(0, DB::table('pieces_a_fournir')->count());
    }

    /** L'isolation : la pièce d'une autre école est introuvable, du même corps qu'un id absent. */
    public function test_404_la_piece_d_une_autre_ecole(): void
    {
        $id = $this->creer($this->membre($this->ecole(), 'admin'), self::PIECE)->json('data.id');
        $admin = $this->membre($this->ecole(), 'admin');

        $absente = $this->asTenant($admin)->putJson('/api/pieces-a-fournir/999999', ['libelle' => 'X'])->assertNotFound();
        self::assertSame($absente->json(), $this->asTenant($admin)->putJson("/api/pieces-a-fournir/{$id}", ['libelle' => 'X'])->assertNotFound()->json());
        $this->asTenant($admin)->getJson('/api/pieces-a-fournir')->assertJsonCount(0, 'data');
    }

    public function test_403_hors_de_la_gestion_et_en_ecole_klassci(): void
    {
        $ecole = $this->ecole();
        $this->creer($this->membre($ecole, 'enseignant'), self::PIECE)->assertForbidden();
        $this->creer($this->membre($ecole, 'etudiant'), self::PIECE)->assertForbidden();

        $klassci = $this->ecole(InstitutionMode::Klassci);
        $this->creer($this->membre($klassci, 'admin'), self::PIECE)->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        $this->asTenant($this->membre($klassci, 'admin'))->getJson('/api/pieces-a-fournir')->assertForbidden();
        self::assertSame(0, DB::table('pieces_a_fournir')->count());
    }

    /** @param  array<string, mixed>  $corps */
    private function creer(User $acteur, array $corps): TestResponse
    {
        return $this->asTenant($acteur)->postJson('/api/pieces-a-fournir', $corps);
    }
}
