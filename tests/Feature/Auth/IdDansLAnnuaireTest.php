<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1536 — le compte connecté dit son id DANS L'ESPACE DE L'ANNUAIRE des enseignants de son
 * école, `id_dans_l_annuaire` : le front y reconnaît « moi » sans connaître le mode (#805).
 *
 * L'annuaire n'émet pas ses ids dans le même espace selon l'école (#1396) : l'id local en école
 * autonome, l'id d'enseignant KLASSCI en école KLASSCI. Chaque test compare la valeur exposée à
 * l'`id` que l'annuaire RÉEL (`GET /api/proxy/enseignants`) émet pour ce même compte : jamais à
 * une constante. C'est aussi ce qui garde la paire de sources que le service de résolution
 * recopie du fournisseur de l'annuaire.
 *
 * Le champ doit EXISTER, même nul : un champ absent se lit `null` et ferait passer les cas nuls.
 */
final class IdDansLAnnuaireTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'mot-de-passe-de-test';

    public function test_ecole_autonome_la_connexion_locale_rend_l_id_que_l_annuaire_emet(): void
    {
        $compte = $this->enseignant(Institution::factory()->standalone()->create(), null, null);

        $reponse = $this->postJson('/api/auth/login', ['username' => $compte->email, 'password' => self::MOT_DE_PASSE])->assertOk();

        $expose = $this->champ((array) $reponse->json('data.user'));
        self::assertNotNull($expose);
        self::assertSame($this->idDansLAnnuaireServi($compte), $expose);
        self::assertSame($compte->id, $expose, 'En école autonome, l\'annuaire émet l\'id local.');
        self::assertArrayNotHasKey('mode', (array) $reponse->json('meta'), 'Le mode n\'est jamais exposé (#805).');
    }

    public function test_ecole_autonome_auth_me_rend_l_id_que_l_annuaire_emet(): void
    {
        $compte = $this->enseignant(Institution::factory()->standalone()->create(), null, null);

        $expose = $this->champ((array) $this->asTenant($compte)->getJson('/api/auth/me')->assertOk()->json('data'));

        self::assertNotNull($expose);
        self::assertSame($this->idDansLAnnuaireServi($compte), $expose);
    }

    public function test_ecole_klassci_auth_me_rend_l_id_que_l_annuaire_relaie(): void
    {
        $compte = $this->enseignant(Institution::factory()->create(), 9001, 42);
        $this->annuaireKlassci([
            ['id' => 42, 'user_id' => 9001, 'nom' => 'Dupont', 'email' => $compte->email],
            ['id' => 43, 'user_id' => 9002, 'nom' => 'Martin', 'email' => 'martin@ecole.test'],
        ]);

        $expose = $this->champ((array) $this->asTenant($compte)->getJson('/api/auth/me')->assertOk()->json('data'));

        self::assertNotNull($expose);
        self::assertSame($this->idDansLAnnuaireServi($compte), $expose);
        self::assertNotSame($compte->id, $expose, 'En école KLASSCI, l\'id local n\'est pas celui de l\'annuaire.');
    }

    public function test_ecole_klassci_auth_me_rend_l_id_que_l_annuaire_emet_en_repli_local(): void
    {
        $compte = $this->enseignant(Institution::factory()->create(), 9001, 42);
        $this->annuaireKlassci([]);

        $expose = $this->champ((array) $this->asTenant($compte)->getJson('/api/auth/me')->assertOk()->json('data'));

        self::assertNotNull($expose);
        self::assertSame($this->idDansLAnnuaireServi($compte), $expose);
    }

    public function test_la_connexion_klassci_rend_l_id_que_l_annuaire_relaie(): void
    {
        Institution::factory()->create(['slug' => 'demo']);
        $this->connexionKlassci(9001, 42);
        $reponse = $this->postJson('/api/auth/login', ['username' => 'dupont', 'password' => self::MOT_DE_PASSE])->assertOk();
        $compte = User::query()->where('email', 'dupont@demo.test')->firstOrFail();
        // Sans jeton KLASSCI, la lecture de l'annuaire ne déclenche aucune re-synchronisation.
        $compte->forceFill(['klassci_token' => null])->save();
        $this->annuaireKlassci([['id' => 42, 'user_id' => 9001, 'nom' => 'Dupont', 'email' => $compte->email]]);

        $expose = $this->champ((array) $reponse->json('data.user'));

        self::assertNotNull($expose);
        self::assertSame($this->idDansLAnnuaireServi($compte), $expose);
    }

    /** Verrou : sans identité d'enseignant KLASSCI, le compte est écarté de l'annuaire (#1396), et nul ici. */
    public function test_ecole_klassci_un_enseignant_sans_identite_est_nul_et_absent_de_l_annuaire(): void
    {
        $compte = $this->enseignant(Institution::factory()->create(), 9001, null);
        $this->annuaireKlassci([]);

        $expose = $this->champ((array) $this->asTenant($compte)->getJson('/api/auth/me')->assertOk()->json('data'));

        self::assertNull($expose);
        self::assertNull($this->idDansLAnnuaireServi($compte));
    }

    /** Verrou : l'annuaire ne liste que des enseignants ; un coordinateur n'y a pas d'id. */
    public function test_un_coordinateur_n_a_pas_d_id_dans_l_annuaire(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $coordinateur = User::factory()->for($ecole)->create(['role' => 'coordinateur', 'klassci_id' => null, 'klassci_enseignant_id' => null]);
        $this->enseignant($ecole, null, null);

        $expose = $this->champ((array) $this->asTenant($coordinateur)->getJson('/api/auth/me')->assertOk()->json('data'));

        self::assertNull($expose);
        $ids = array_column((array) $this->asTenant($coordinateur)->getJson('/api/proxy/enseignants')->json('data'), 'id');
        self::assertNotContains($coordinateur->id, $ids);
    }

    /**
     * Le champ, qui doit exister même nul.
     *
     * @param  array<array-key, mixed>  $compte
     */
    private function champ(array $compte): ?int
    {
        self::assertArrayHasKey('id_dans_l_annuaire', $compte, 'Le champ manque : le front ne distingue pas « aucun » de « non dit ».');
        $valeur = $compte['id_dans_l_annuaire'];
        self::assertTrue($valeur === null || is_int($valeur), 'Un entier ou null.');

        return $valeur;
    }

    /** L'`id` de la ligne que l'annuaire sert à ce compte (un enseignant ne voit que la sienne), ou null. */
    private function idDansLAnnuaireServi(User $compte): ?int
    {
        /** @var TestResponse<Response> $annuaire */
        $annuaire = $this->asTenant($compte)->getJson('/api/proxy/enseignants')->assertOk();
        $lignes = array_values(array_filter(
            (array) $annuaire->json('data'),
            static fn (mixed $ligne): bool => is_array($ligne) && ($ligne['email'] ?? null) === $compte->email,
        ));

        return isset($lignes[0]['id']) && is_int($lignes[0]['id']) ? $lignes[0]['id'] : null;
    }

    private function enseignant(Institution $ecole, ?int $klassciId, ?int $enseignantId): User
    {
        return User::factory()->for($ecole)->teacher()->create([
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => $klassciId,
            'klassci_enseignant_id' => $enseignantId,
            'klassci_token' => null,
        ]);
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function annuaireKlassci(array $lignes): void
    {
        $this->mock(KlassciProxyService::class, function ($mock) use ($lignes): void {
            $mock->shouldReceive('getEnseignants')->andReturn(['success' => true, 'data' => $lignes]);
        });
    }

    private function connexionKlassci(int $compte, int $enseignant): void
    {
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andReturn([['code' => 'demo', 'api_base_url' => 'https://demo.klassci.test']]);
        });
        $this->mock(KlassciAuthClient::class, function ($mock) use ($compte, $enseignant): void {
            $mock->shouldReceive('attemptLogin')->andReturn([
                'data' => [
                    'user' => [
                        'id' => $compte, 'enseignant_id' => $enseignant, 'nom' => 'Dupont', 'name' => 'Dupont',
                        'email' => 'dupont@demo.test', 'role' => 'enseignant',
                    ],
                    'token' => 'klassci-token',
                ],
                'meta' => [],
            ]);
        });
    }
}
