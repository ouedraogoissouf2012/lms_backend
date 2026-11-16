<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #930 : la clé du cache de recherche concaténait requête, id et limite SANS
 * séparateur. La requête `abc9` du compte A et la requête `abc` du compte `9A`
 * avaient la même clé : l'agrégat de l'un était servi à l'autre, y compris le
 * compartiment `users` (noms, courriels) que seul un gestionnaire reçoit.
 *
 * Les ids ne sont PAS écrits en dur : sous MySQL, l'auto-incrément ne repart pas à 1
 * entre les tests. L'apprenant reçoit l'id `9A`, construit sur l'id RÉEL du
 * coordinateur, et la prémisse (les deux concaténations coïncident) est vérifiée
 * avant tout appel : sinon le test échouerait sur elle, au lieu de passer à vide.
 *
 * Volets croisés : le coordinateur et l'apprenant sont dans deux écoles, l'une
 * KLASSCI, l'autre du LMS, dans les deux sens. Vrai jeton.
 */
final class CleDuCacheDeRechercheTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const LIMITE = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: bool, 1: bool}> */
    public static function volets(): iterable
    {
        yield 'coordinateur KLASSCI, apprenant du LMS' => [false, true];
        yield 'coordinateur du LMS, apprenant KLASSCI' => [true, false];
    }

    /** @return iterable<string, array{0: bool}> */
    public static function ecoles(): iterable
    {
        yield 'école KLASSCI' => [false];
        yield 'école du LMS' => [true];
    }

    #[DataProvider('volets')]
    public function test_deux_comptes_dont_la_concatenation_coincide_ne_partagent_pas_le_cache(bool $coordLms, bool $eleveLms): void
    {
        $ecoleCoord = $this->ecole($coordLms);
        $coordinateur = $this->compte($ecoleCoord, 'coordinateur');
        $this->compte($ecoleCoord, 'enseignant', 'abc9 Cible');
        $eleve = $this->compte($this->ecole($eleveLms), 'etudiant', null, (int) ('9'.$coordinateur->id));

        // La prémisse : sans séparateur, les deux clés coïncideraient.
        self::assertSame('abc9'.$coordinateur->id.self::LIMITE, 'abc'.$eleve->id.self::LIMITE);

        $trouve = $this->chercher($coordinateur, 'abc9');
        self::assertContains('abc9 Cible', array_column((array) $trouve->json('results.users'), 'title'), 'le coordinateur trouve le compte');

        $this->chercher($eleve, 'abc')->assertJsonPath('results.users', []);
    }

    /**
     * Le rôle est dans la clé : un apprenant promu coordinateur ne relit pas l'agrégat
     * calculé sous ses anciens droits (sans le compartiment `users`).
     */
    #[DataProvider('ecoles')]
    public function test_un_compte_promu_ne_relit_pas_l_agregat_de_ses_anciens_droits(bool $lms): void
    {
        $ecole = $this->ecole($lms);
        $compte = $this->compte($ecole, 'etudiant');
        $this->compte($ecole, 'enseignant', 'abc9 Cible');

        $this->chercher($compte, 'abc9')->assertJsonPath('results.users', []);
        $compte->forceFill(['role' => 'coordinateur'])->save();

        self::assertContains('abc9 Cible', array_column((array) $this->chercher($compte->fresh() ?? $compte, 'abc9')->json('results.users'), 'title'));
    }

    /** Témoin : la même recherche du même compte est bien relue dans le cache. */
    public function test_la_meme_recherche_du_meme_compte_est_relue_dans_le_cache(): void
    {
        $ecole = $this->ecole(true);
        $coordinateur = $this->compte($ecole, 'coordinateur');
        $cible = $this->compte($ecole, 'enseignant', 'abc9 Cible');

        $this->chercher($coordinateur, 'abc9');
        $cible->forceDelete();

        self::assertContains('abc9 Cible', array_column((array) $this->chercher($coordinateur, 'abc9')->json('results.users'), 'title'));
    }

    /** @return TestResponse<Response> */
    private function chercher(User $compte, string $requete): TestResponse
    {
        return $this->asTenant($compte)
            ->getJson('/api/search?'.http_build_query(['query' => $requete, 'limit' => self::LIMITE]))
            ->assertOk();
    }

    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role, ?string $nom = null, ?int $id = null): User
    {
        $attributs = ['institution_id' => $ecole->id, 'role' => $role, 'klassci_token' => null];
        if ($nom !== null) {
            $attributs['name'] = $nom;
        }
        if ($id !== null) {
            $attributs['id'] = $id;
        }

        return User::factory()->create($attributs);
    }
}
