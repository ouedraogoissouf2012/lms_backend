<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1728 : `PUT /api/users/{user}` (modifier un compte) et `DELETE /api/users/{user}` (le désactiver,
 * suppression logique) suivent `docs/openapi.yaml`, réponse par réponse, sous vrai jeton. Débloque
 * l'écran de gestion des comptes (frontend_lms#656), qui lit ses champs et ses 422 sur la spec.
 *
 * La cible est bornée par `GestionDeCompte` : un rang strictement inférieur à celui de l'acteur,
 * jamais soi-même (403). Le nouveau rôle l'est par `AssignableRole` (422 sur `role`). Un compte
 * d'une autre école est introuvable (404), pas interdit.
 */
final class GererUnCompteSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/users/{user}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_put_200_l_admin_renomme_un_enseignant_de_son_ecole(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $cible = $this->membre($ecole, 'enseignant');

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))
            ->putJson($this->url($cible), ['name' => 'Nom Corrigé', 'role' => 'coordinateur']);

        $reponse->assertOk()
            ->assertJsonPath('data.id', $cible->id)
            ->assertJsonPath('data.name', 'Nom Corrigé')
            ->assertJsonPath('data.role', 'coordinateur');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'put', 'data');
    }

    public function test_put_403_le_coordinateur_ne_modifie_pas_un_admin(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->putJson($this->url($this->membre($ecole, 'admin')), ['name' => 'X']);

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
    }

    public function test_put_403_un_enseignant_ne_gere_aucun_compte(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'enseignant'))
            ->putJson($this->url($this->membre($ecole, 'etudiant')), ['name' => 'X']);

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
    }

    public function test_put_404_un_compte_d_une_autre_ecole(): void
    {
        $cible = $this->membre($this->ecole(), 'enseignant');

        $reponse = $this->asTenant($this->membre($this->ecole(), 'admin'))->putJson($this->url($cible), ['name' => 'X']);

        $reponse->assertNotFound();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
        $this->assertNotSame('X', $cible->fresh()?->name);
    }

    public function test_put_422_un_role_egal_a_celui_de_l_acteur(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))
            ->putJson($this->url($this->membre($ecole, 'enseignant')), ['role' => 'admin']);

        $reponse->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
    }

    public function test_put_422_un_email_deja_pris_dans_l_ecole(): void
    {
        $ecole = $this->ecole();
        $existant = $this->membre($ecole, 'etudiant');

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))
            ->putJson($this->url($this->membre($ecole, 'enseignant')), ['email' => $existant->email]);

        $reponse->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
    }

    public function test_put_401_sans_jeton(): void
    {
        $reponse = $this->putJson($this->url($this->membre($this->ecole(), 'etudiant')), ['name' => 'X']);

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
    }

    #[DataProvider('volets')]
    public function test_delete_200_l_admin_desactive_un_eleve_de_son_ecole(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $cible = $this->membre($ecole, 'etudiant');

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->deleteJson($this->url($cible));

        $reponse->assertOk()->assertJsonMissingPath('data');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'delete');
        $this->assertSoftDeleted('users', ['id' => $cible->id]);
    }

    public function test_delete_403_jamais_son_propre_compte(): void
    {
        $admin = $this->membre($this->ecole(), 'admin');

        $reponse = $this->asTenant($admin)->deleteJson($this->url($admin));

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'delete');
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_delete_404_un_compte_d_une_autre_ecole(): void
    {
        $cible = $this->membre($this->ecole(), 'etudiant');

        $reponse = $this->asTenant($this->membre($this->ecole(), 'admin'))->deleteJson($this->url($cible));

        $reponse->assertNotFound();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'delete');
        $this->assertNotSoftDeleted('users', ['id' => $cible->id]);
    }

    public function test_delete_401_sans_jeton(): void
    {
        $reponse = $this->deleteJson($this->url($this->membre($this->ecole(), 'etudiant')));

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'delete');
    }

    private function url(User $cible): string
    {
        return '/api/users/'.$cible->id;
    }
}
