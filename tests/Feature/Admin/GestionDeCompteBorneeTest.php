<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #932 : la suppression (`DELETE /api/users/{user}`) et la modification
 * (`PUT /api/users/{user}`) d'un compte ne bornaient que le rôle de l'ACTEUR. La règle
 * de cible est celle de la création (`AssignableRole`) : l'acteur ne gère qu'un
 * compte de rang STRICTEMENT inférieur au sien, et jamais le sien. Elle vaut pour la
 * suppression ET la modification.
 *
 * Témoin du rang supérieur : superAdmin. La route n'admet pas encore `admin` (#1102) ;
 * le témoin admin s'ajoute à la repose de cette PR après #1102.
 *
 * Les deux types d'école. Vrai jeton.
 */
final class GestionDeCompteBorneeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool}> */
    public static function ecoles(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école du LMS' => [true];
    }

    #[DataProvider('ecoles')]
    public function test_le_coordinateur_supprime_et_modifie_un_enseignant_et_un_eleve(bool $lms): void
    {
        $ecole = $this->ecole($lms);
        $coordinateur = $this->compte($ecole, 'coordinateur');

        foreach (['enseignant', 'etudiant'] as $role) {
            $cible = $this->compte($ecole, $role);
            $this->asTenant($coordinateur)->putJson("/api/users/{$cible->id}", ['name' => 'Nom modifié'])->assertOk();
            self::assertSame('Nom modifié', $cible->fresh()?->name);

            $this->asTenant($coordinateur)->deleteJson("/api/users/{$cible->id}")->assertOk();
            $this->assertSoftDeleted('users', ['id' => $cible->id]);
        }
    }

    #[DataProvider('ecoles')]
    public function test_un_coordinateur_ne_supprime_pas_un_compte_de_rang_egal_ou_superieur(bool $lms): void
    {
        $ecole = $this->ecole($lms);
        $coordinateur = $this->compte($ecole, 'coordinateur');

        foreach ($this->cibles($ecole, $coordinateur) as $cas => $cible) {
            self::assertSame(403, $this->asTenant($coordinateur)->deleteJson("/api/users/{$cible->id}")->status(), $cas);
            $this->assertNotSoftDeleted('users', ['id' => $cible->id]);
        }
    }

    #[DataProvider('ecoles')]
    public function test_un_coordinateur_ne_modifie_pas_un_compte_admin(bool $lms): void
    {
        $ecole = $this->ecole($lms);
        $coordinateur = $this->compte($ecole, 'coordinateur');

        foreach ($this->cibles($ecole, $coordinateur) as $cas => $cible) {
            $avant = $cible->only(['email', 'role', 'name']);
            foreach ([['email' => "autre.{$cible->id}@exemple.test"], ['role' => 'etudiant'], ['name' => 'Autre nom']] as $champ) {
                $reponse = $this->asTenant($coordinateur)->putJson("/api/users/{$cible->id}", $champ);
                self::assertSame(403, $reponse->status(), $cas.' : '.(string) array_key_first($champ));
            }
            self::assertSame($avant, $cible->fresh()?->only(['email', 'role', 'name']), $cas.' : inchangé');
        }
    }

    /** Témoin : un rang supérieur modifie et supprime un coordinateur. */
    #[DataProvider('ecoles')]
    public function test_un_rang_superieur_gere_un_coordinateur(bool $lms): void
    {
        $ecole = $this->ecole($lms);
        $coordinateur = $this->compte($ecole, 'coordinateur');
        $superAdmin = $this->compte($ecole, 'superAdmin');

        $this->asTenant($superAdmin)->putJson("/api/users/{$coordinateur->id}", ['name' => 'Nom modifié'])->assertOk();
        $this->asTenant($superAdmin)->deleteJson("/api/users/{$coordinateur->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $coordinateur->id]);
    }

    /** @return array<string, User> */
    private function cibles(Institution $ecole, User $coordinateur): array
    {
        return [
            'un admin' => $this->compte($ecole, 'admin'),
            'un superAdmin' => $this->compte($ecole, 'superAdmin'),
            'un pair' => $this->compte($ecole, 'coordinateur'),
            'lui-même' => $coordinateur,
        ];
    }

    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => null]);
    }
}
