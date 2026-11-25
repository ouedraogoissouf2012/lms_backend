<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\CompteSansEtablissementException;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1181 — aucun compte d'école ne se crée sans établissement : UNE règle, au
 * modèle, pour tous les écrivains, au lieu d'être tenue par chacun.
 *
 * Avant, le hook de `BelongsToInstitution` journalisait et laissait passer quand
 * aucun tenant n'était résolu : un appelant futur sans tenant aurait créé un compte
 * d'école sans école, sans erreur. Seul le supradmin de plateforme (rôle EXACT
 * `supradmin`) vit sans établissement.
 */
final class CompteSansEtablissementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantManager::class)->reset();
    }

    /** @return array<string, array{0: string}> */
    public static function rolesDEcole(): array
    {
        return [
            'élève' => ['etudiant'],
            'enseignant' => ['enseignant'],
            'coordinateur' => ['coordinateur'],
            'admin' => ['admin'],
            'admin d\'école' => ['superAdmin'],
            'variante de supradmin' => ['Supradmin'],
        ];
    }

    #[DataProvider('rolesDEcole')]
    public function test_un_compte_d_ecole_sans_etablissement_ne_se_cree_pas(string $role): void
    {
        $refuse = $this->creer(['role' => $role, 'institution_id' => null]);

        self::assertTrue($refuse, "Un compte « $role » sans établissement doit être refusé.");
        self::assertSame(0, User::withoutGlobalScopes()->count(), 'Aucune ligne écrite.');
    }

    /** Sans `institution_id` et sans tenant : le hook ne peut rien poser, la règle refuse. */
    public function test_sans_tenant_et_sans_etablissement_le_compte_est_refuse(): void
    {
        $refuse = $this->creer(['role' => 'enseignant']);

        self::assertTrue($refuse);
        self::assertSame(0, User::withoutGlobalScopes()->count());
    }

    /** Verrou : le supradmin de plateforme se crée sans établissement. */
    public function test_le_supradmin_se_cree_sans_etablissement(): void
    {
        self::assertFalse($this->creer(['role' => 'supradmin', 'institution_id' => null]));
        self::assertNull(User::withoutGlobalScopes()->where('role', 'supradmin')->value('institution_id'));
    }

    /** Verrou : un établissement explicite, ou celui du tenant résolu. */
    public function test_un_compte_avec_un_etablissement_se_cree(): void
    {
        $ecole = Institution::factory()->create();

        self::assertFalse($this->creer(['role' => 'enseignant', 'institution_id' => $ecole->id]));

        app(TenantManager::class)->set($ecole);
        self::assertFalse($this->creer(['role' => 'etudiant']));
        self::assertSame(2, User::withoutGlobalScopes()->where('institution_id', $ecole->id)->count());
    }

    /**
     * La fabrique des tests : un compte d'école a une école. Celle du tenant résolu
     * si l'on en a un, sinon une école neuve ; le supradmin reste sans.
     */
    public function test_la_fabrique_donne_une_ecole_a_un_compte_d_ecole(): void
    {
        self::assertNotNull(User::factory()->create(['role' => 'etudiant'])->institution_id);
        self::assertNull(User::factory()->create(['role' => 'supradmin'])->institution_id);

        $ecole = Institution::factory()->create();
        app(TenantManager::class)->set($ecole);
        self::assertSame($ecole->id, User::factory()->create(['role' => 'enseignant'])->institution_id);
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @return bool vrai si la création a été refusée par la règle
     */
    private function creer(array $attributs): bool
    {
        try {
            User::query()->create($attributs + [
                'name' => 'Compte 1181',
                'email' => uniqid('c1181-', true).'@ecole.test',
                'password' => 'mot-de-passe-1181',
            ]);
        } catch (CompteSansEtablissementException) {
            return true;
        }

        return false;
    }
}
