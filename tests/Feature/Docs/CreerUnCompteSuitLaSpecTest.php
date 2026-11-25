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
 * #1625 : `POST /api/users` (la création d'un compte par l'école) suit `docs/openapi.yaml`, réponse
 * par réponse, sous vrai jeton (`asTenant`). Débloque l'écran de création de comptes
 * (frontend_lms#649).
 *
 * Le rôle demandé doit être strictement inférieur à celui de l'acteur : un rôle trop haut est un
 * 422 sur `role` (`AssignableRole`), pas un 403.
 */
final class CreerUnCompteSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/users';

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
    public function test_201_l_admin_cree_un_enseignant_dans_son_ecole(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))->postJson('/api'.self::CHEMIN, $this->compte('enseignant'));

        $reponse->assertCreated()
            ->assertJsonPath('data.role', 'enseignant')
            ->assertJsonPath('data.institution_id', $ecole->getKey());
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
    }

    public function test_403_un_enseignant_ne_cree_pas_de_compte(): void
    {
        $reponse = $this->asTenant($this->membre($this->ecole(), 'enseignant'))->postJson('/api'.self::CHEMIN, $this->compte('etudiant'));

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_403_un_compte_sans_ecole(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $reponse = $this->asTenant($supradmin)->postJson('/api'.self::CHEMIN, $this->compte('etudiant'));

        $reponse->assertForbidden()->assertJsonPath('reason', 'no_institution');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_422_un_role_egal_ou_superieur_a_celui_de_l_acteur(): void
    {
        $reponse = $this->asTenant($this->membre($this->ecole(), 'coordinateur'))->postJson('/api'.self::CHEMIN, $this->compte('admin'));

        $reponse->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_422_un_mot_de_passe_trop_court_ou_non_confirme(): void
    {
        $compte = ['password' => 'court', 'password_confirmation' => 'autre'] + $this->compte('etudiant');

        $reponse = $this->asTenant($this->membre($this->ecole(), 'admin'))->postJson('/api'.self::CHEMIN, $compte);

        $reponse->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_422_un_email_deja_pris_dans_l_ecole(): void
    {
        $ecole = $this->ecole();
        $existant = $this->membre($ecole, 'etudiant');

        $reponse = $this->asTenant($this->membre($ecole, 'admin'))
            ->postJson('/api'.self::CHEMIN, ['email' => $existant->email] + $this->compte('etudiant'));

        $reponse->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_401_sans_jeton(): void
    {
        $reponse = $this->postJson('/api'.self::CHEMIN, $this->compte('etudiant'));

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** @return array<string, string> */
    private function compte(string $role): array
    {
        return [
            'name' => 'Compte Démo',
            'email' => 'compte.'.$role.'@demo.test',
            'password' => 'MotDePasse1!',
            'password_confirmation' => 'MotDePasse1!',
            'role' => $role,
        ];
    }
}
