<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InstitutionMode;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1246 — la gestion (`POST` et `PUT /api/users`) accepte un alias de rôle en entrée, et
 * l'écrit sous sa valeur CANONIQUE. Avant, `teacher` restait `teacher` en base, et les
 * lectures qui comparent la valeur canonique (`->where('role', 'etudiant')`) ne voyaient
 * pas le compte.
 *
 * Vrai jeton, `asTenant()`, les deux types d'école : la garde `gestion` ne lit pas le mode.
 */
final class RoleCanoniqueParLaGestionTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** Les alias que `Role::tryFromString` reconnaît, et leur valeur canonique. */
    private const ALIAS = [
        'teacher' => 'enseignant',
        'student' => 'etudiant',
        'étudiant' => 'etudiant',
        'coordinator' => 'coordinateur',
        'administrateur' => 'admin',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: string}> */
    public static function aliasDansLesDeuxVolets(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            foreach (self::ALIAS as $alias => $canonique) {
                yield "{$volet}, {$alias}" => [$mode, $alias, $canonique];
            }
        }
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function valeursInconnuesDansLesDeuxVolets(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            // `Etudiant` : `tryFromString` est en correspondance exacte (#949). La valeur
            // n'est pas reconnue, donc laissée telle quelle et refusée, comme avant.
            foreach (['directeur', 'Etudiant'] as $valeur) {
                yield "{$volet}, {$valeur}" => [$mode, $valeur];
            }
        }
    }

    #[DataProvider('aliasDansLesDeuxVolets')]
    public function test_un_alias_cree_un_compte_au_role_canonique(InstitutionMode $mode, string $alias, string $canonique): void
    {
        $this->asTenant($this->superAdmin($mode))
            ->postJson('/api/users', $this->nouveauCompte('alias', $alias))
            ->assertCreated();

        self::assertSame($canonique, $this->roleStocke('alias@ecole.test'));
    }

    #[DataProvider('aliasDansLesDeuxVolets')]
    public function test_un_alias_modifie_le_compte_au_role_canonique(InstitutionMode $mode, string $alias, string $canonique): void
    {
        $acteur = $this->superAdmin($mode);
        $cible = $this->compte($acteur, 'etudiant', 'cible@ecole.test');

        $this->asTenant($acteur)->putJson('/api/users/'.$cible->id, ['role' => $alias])->assertOk();

        self::assertSame($canonique, $this->roleStocke('cible@ecole.test'));
    }

    /** Contrat déclaré : la réponse rend la valeur écrite, pas l'alias envoyé. */
    #[DataProvider('volets')]
    public function test_la_reponse_rend_le_role_canonique(InstitutionMode $mode): void
    {
        $acteur = $this->superAdmin($mode);
        $cible = $this->compte($acteur, 'etudiant', 'cible@ecole.test');

        $creation = $this->asTenant($acteur)->postJson('/api/users', $this->nouveauCompte('reponse', 'teacher'));
        $modification = $this->asTenant($acteur)->putJson('/api/users/'.$cible->id, ['role' => 'coordinator']);

        self::assertSame('enseignant', $creation->assertCreated()->json('data.role'));
        self::assertSame('coordinateur', $modification->assertOk()->json('data.role'));
    }

    #[DataProvider('volets')]
    public function test_une_valeur_canonique_s_ecrit_telle_quelle(InstitutionMode $mode): void
    {
        $this->asTenant($this->superAdmin($mode))
            ->postJson('/api/users', $this->nouveauCompte('canonique', 'enseignant'))
            ->assertCreated();

        self::assertSame('enseignant', $this->roleStocke('canonique@ecole.test'));
    }

    #[DataProvider('valeursInconnuesDansLesDeuxVolets')]
    public function test_une_valeur_inconnue_est_refusee_et_rien_n_est_ecrit(InstitutionMode $mode, string $valeur): void
    {
        $acteur = $this->superAdmin($mode);
        $cible = $this->compte($acteur, 'etudiant', 'cible@ecole.test');

        $this->asTenant($acteur)->postJson('/api/users', $this->nouveauCompte('inconnu', $valeur))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->asTenant($acteur)->putJson('/api/users/'.$cible->id, ['role' => $valeur])
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        self::assertNull($this->roleStocke('inconnu@ecole.test'), 'Aucun compte créé.');
        self::assertSame('etudiant', $this->roleStocke('cible@ecole.test'));
    }

    /** L'alias est jugé sous sa valeur canonique : il n'ouvre aucune escalade. */
    #[DataProvider('volets')]
    public function test_l_escalade_reste_refusee_malgre_l_alias(InstitutionMode $mode): void
    {
        $coordinateur = $this->gestion($mode, 'coordinateur');

        foreach (['administrateur', 'coordinator'] as $alias) {
            $this->asTenant($coordinateur)->postJson('/api/users', $this->nouveauCompte('escalade', $alias))
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }

        self::assertNull($this->roleStocke('escalade@ecole.test'));
    }

    /** Une modification sans rôle n'en reçoit aucun : le rôle stocké ne bouge pas. */
    #[DataProvider('volets')]
    public function test_une_modification_sans_role_garde_le_role(InstitutionMode $mode): void
    {
        $acteur = $this->superAdmin($mode);
        $cible = $this->compte($acteur, 'enseignant', 'cible@ecole.test');

        $this->asTenant($acteur)->putJson('/api/users/'.$cible->id, ['name' => 'Nouveau nom'])->assertOk();

        self::assertSame('enseignant', $this->roleStocke('cible@ecole.test'));
    }

    public function test_la_normalisation_n_ajoute_aucune_cle_role(): void
    {
        $requete = UpdateUserRequest::create('/api/users/1', 'PUT', ['name' => 'Nouveau nom']);

        (new ReflectionMethod($requete, 'prepareForValidation'))->invoke($requete);

        self::assertFalse($requete->has('role'));
    }

    private function superAdmin(InstitutionMode $mode): User
    {
        return $this->gestion($mode, 'superAdmin');
    }

    private function gestion(InstitutionMode $mode, string $role): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->for($ecole)->create(['role' => $role, 'last_klassci_sync' => now()]);
    }

    private function compte(User $acteur, string $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'institution_id' => $acteur->institution_id]);
    }

    /** @return array<string, string> */
    private function nouveauCompte(string $nom, string $role): array
    {
        return [
            'name' => 'Compte '.$nom,
            'email' => $nom.'@ecole.test',
            'password' => 'mot-de-passe-1246',
            'password_confirmation' => 'mot-de-passe-1246',
            'role' => $role,
        ];
    }

    private function roleStocke(string $email): mixed
    {
        return User::withoutGlobalScopes()->where('email', $email)->value('role');
    }
}
