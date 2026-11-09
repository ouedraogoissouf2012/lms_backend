<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La lecture des enseignants suit la grille des rôles
 * (`TeacherDirectoryVisibility`), sur les deux routes qui la servent :
 * - la gestion (coordinateur, admin, superAdmin) lit toute la liste ;
 * - un enseignant lit sa seule ligne ;
 * - tout autre rôle (élève, rôle non reconnu) lit une liste vide, sans appel à
 *   KLASSCI.
 *
 * La ligne d'un enseignant se reconnaît à son identité, chaque clé comparée à
 * l'identifiant de son propre espace. Jeton porteur réel, routes réelles.
 */
final class LectureDesEnseignantsTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    /**
     * Trois lignes KLASSCI. La troisième porte, dans l'espace des enseignants,
     * l'identifiant que la première porte dans l'espace des comptes (9001).
     */
    private const LIGNES = [
        ['id' => 501, 'enseignant_id' => 501, 'user_id' => 9001, 'nom' => 'Ligne A'],
        ['id' => 502, 'user_id' => 9002, 'nom' => 'Ligne B'],
        ['id' => 9001, 'user_id' => 7777, 'nom' => 'Ligne C'],
    ];

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'klassci_api_token' => 'jeton-ecole',
            'is_active' => true,
        ]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function routes(): iterable
    {
        yield 'proxy' => ['/api/proxy/enseignants'];
        yield 'lms' => ['/api/lms/enseignants'];
        yield 'lms, avec détails' => ['/api/lms/enseignants?with_details=true'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function gestionEtRoutes(): iterable
    {
        foreach (['coordinateur', 'admin', 'superAdmin'] as $role) {
            foreach (self::routes() as $nom => [$uri]) {
                yield "{$role}, {$nom}" => [$role, $uri];
            }
        }
    }

    #[DataProvider('gestionEtRoutes')]
    public function test_la_gestion_lit_toute_la_liste(string $role, string $uri): void
    {
        $this->klassciRepond(self::LIGNES);

        $this->lire($this->compte($role), $uri)->assertOk()->assertJsonPath('data', self::LIGNES);
    }

    /** @return iterable<string, array{0: string, 1: ?int, 2: ?int, 3: list<string>}> */
    public static function enseignants(): iterable
    {
        foreach (self::routes() as $nom => [$uri]) {
            yield "par l'espace des enseignants, {$nom}" => [$uri, 501, null, ['Ligne A']];
            yield "par l'espace des comptes, {$nom}" => [$uri, null, 9002, ['Ligne B']];
            // Son klassci_id (9001) est l'id ENSEIGNANT de la ligne C : il ne la voit pas.
            yield "collision entre espaces, {$nom}" => [$uri, 501, 9001, ['Ligne A']];
            // KLASSCI n'a pas fourni d'identifiant enseignant : le résolveur a recopié
            // le klassci_id (9001), qui est aussi l'id ENSEIGNANT de la ligne C.
            // L'identifiant ambigu n'est pas lu : seule A (par user_id) lui revient.
            yield "identifiant enseignant recopié du compte, {$nom}" => [$uri, 9001, 9001, ['Ligne A']];
            yield "sans identifiant, {$nom}" => [$uri, null, null, []];
        }
    }

    /** @param  list<string>  $noms */
    #[DataProvider('enseignants')]
    public function test_un_enseignant_ne_lit_que_sa_ligne(string $uri, ?int $enseignantId, ?int $klassciId, array $noms): void
    {
        $this->klassciRepond(self::LIGNES);
        $enseignant = $this->compte('enseignant', ['klassci_enseignant_id' => $enseignantId, 'klassci_id' => $klassciId]);

        $reponse = $this->lire($enseignant, $uri)->assertOk();

        self::assertSame($noms, array_column((array) $reponse->json('data'), 'nom'));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function autresRoles(): iterable
    {
        foreach (['etudiant', 'role-inconnu'] as $role) {
            foreach (self::routes() as $nom => [$uri]) {
                yield "{$role}, {$nom}" => [$role, $uri];
            }
        }
    }

    #[DataProvider('autresRoles')]
    public function test_un_autre_role_lit_une_liste_vide_sans_appel_a_klassci(string $role, string $uri): void
    {
        Http::fake();

        $this->lire($this->compte($role), $uri)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    #[DataProvider('autresRoles')]
    public function test_dans_une_ecole_creee_depuis_le_lms_aussi(string $role, string $uri): void
    {
        Http::fake();
        $ecoleLms = Institution::factory()->standalone()->create(['is_active' => true]);
        $compte = User::factory()->create(['institution_id' => $ecoleLms->id, 'role' => $role]);

        $this->lire($compte, $uri)->assertOk()->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    /**
     * Hors de la gestion, la réponse est reconstruite par liste blanche : rien
     * d'autre de la réponse source (autres compteurs du meta, clés de premier
     * niveau) n'est servi.
     */
    public function test_hors_gestion_la_reponse_ne_garde_que_la_liste_blanche(): void
    {
        Http::fake([
            self::BASE_URL.'/enseignants*' => Http::response([
                'success' => true,
                'data' => self::LIGNES,
                'meta' => ['total' => 3, 'count' => 3, 'pages' => 1],
                'resume' => ['enseignants' => 3],
            ]),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
        $enseignant = $this->compte('enseignant', ['klassci_enseignant_id' => 501]);

        $this->lire($enseignant, '/api/proxy/enseignants')->assertOk()->assertExactJson([
            'success' => true,
            'data' => [self::LIGNES[0]],
            'meta' => ['total' => 1],
        ]);

        $lms = $this->lire($enseignant, '/api/lms/enseignants')->assertOk();
        self::assertSame(1, $lms->json('meta.total'));
        self::assertArrayNotHasKey('count', (array) $lms->json('meta'));
        self::assertArrayNotHasKey('pages', (array) $lms->json('meta'));
    }

    /** Une forme non plate n'est pas lue hors de la gestion ; la gestion la reçoit telle quelle. */
    public function test_une_liste_non_plate_n_est_lue_que_par_la_gestion(): void
    {
        $paginee = ['data' => self::LIGNES, 'current_page' => 1, 'total' => 3];
        Http::fake([
            self::BASE_URL.'/enseignants*' => Http::response(['success' => true, 'data' => $paginee]),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);

        $this->lire($this->compte('admin'), '/api/proxy/enseignants')->assertOk()->assertJsonPath('data', $paginee);
        $this->lire($this->compte('enseignant', ['klassci_enseignant_id' => 501]), '/api/proxy/enseignants')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_le_total_du_meta_compte_ce_qui_est_servi(): void
    {
        $this->klassciRepond(self::LIGNES, ['total' => 3]);
        $enseignant = $this->compte('enseignant', ['klassci_enseignant_id' => 501]);

        $this->lire($enseignant, '/api/proxy/enseignants')->assertOk()->assertJsonPath('meta.total', 1);
        $this->lire($enseignant, '/api/lms/enseignants')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_le_repli_local_ne_lit_que_l_etablissement_du_lecteur(): void
    {
        $this->klassciRepond([]);
        $autreEcole = Institution::factory()->create();
        $ici = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant', 'name' => 'Ici']);
        User::factory()->create(['institution_id' => $autreEcole->id, 'role' => 'enseignant', 'name' => 'Ailleurs']);

        $gestion = $this->lire($this->compte('admin'), '/api/proxy/enseignants')->assertOk();
        self::assertSame(['Ici'], array_column((array) $gestion->json('data'), 'nom'));

        $lui = $this->lire($ici, '/api/proxy/enseignants')->assertOk();
        self::assertSame(['Ici'], array_column((array) $lui->json('data'), 'nom'));

        $collegue = $this->compte('enseignant', ['name' => 'Collègue']);
        $saLecture = $this->lire($collegue, '/api/proxy/enseignants')->assertOk();
        self::assertSame(['Collègue'], array_column((array) $saLecture->json('data'), 'nom'), 'Un enseignant ne lit que sa ligne, jamais celle d\'un collègue.');
    }

    /**
     * La borne est explicite : sans tenant résolu, le scope global ne borne rien
     * (il s'ouvre), et la règle doit borner seule.
     */
    public function test_le_repli_local_reste_borne_sans_tenant_resolu(): void
    {
        $autreEcole = Institution::factory()->create();
        User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant', 'name' => 'Ici']);
        User::factory()->create(['institution_id' => $autreEcole->id, 'role' => 'enseignant', 'name' => 'Ailleurs']);
        $gestion = $this->compte('admin');
        app(TenantManager::class)->reset();

        $lus = app(TeacherDirectoryVisibility::class)
            ->restrictLocal($gestion, User::query()->whereIn('role', ['enseignant', 'teacher']))
            ->pluck('name')
            ->all();

        self::assertSame(['Ici'], $lus);
    }

    /** Sans établissement valide, le repli local ne lit rien, même pour la gestion. */
    public function test_le_repli_local_ne_lit_rien_sans_etablissement(): void
    {
        User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant']);
        // Un compte sans établissement : une borne `institution_id = null` le lirait.
        User::factory()->create(['institution_id' => null, 'role' => 'enseignant']);
        $sansEcole = new User(['role' => 'admin']);
        app(TenantManager::class)->reset();

        $lus = app(TeacherDirectoryVisibility::class)
            ->restrictLocal($sansEcole, User::query()->whereIn('role', ['enseignant', 'teacher']))
            ->get();

        self::assertCount(0, $lus);
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(string $role, array $attributs = []): User
    {
        return User::factory()->create(['institution_id' => $this->ecole->id, 'role' => $role] + $attributs);
    }

    private function lire(User $compte, string $uri): TestResponse
    {
        return $this->asTenant($compte)->getJson($uri);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $meta
     */
    private function klassciRepond(array $lignes, array $meta = []): void
    {
        Http::fake([
            self::BASE_URL.'/enseignants*' => Http::response(['success' => true, 'data' => $lignes] + ($meta === [] ? [] : ['meta' => $meta])),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
    }
}
