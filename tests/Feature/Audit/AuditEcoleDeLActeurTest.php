<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * L'école d'une entrée d'audit est celle de l'ACTEUR ; à défaut d'acteur
 * rattaché à une école, celle de la CIBLE.
 *
 * - Une connexion porte l'école du compte qui se connecte (le garde n'est pas
 *   encore authentifié au moment de l'écriture) ; un échec de connexion n'en a
 *   pas, le compte n'étant pas identifié.
 * - Une action du supradmin, ou d'un job système, porte l'école visée : c'est
 *   elle que le supradmin cherche en diagnostiquant.
 * - La cible n'écrase jamais l'école d'un acteur qui en a une.
 *
 * Vrai jeton porteur, vraies routes et vraie commande de purge ; seul le cas
 * « l'acteur garde son école » appelle le service, aucune route ne le permettant.
 */
final class AuditEcoleDeLActeurTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'mot-de-passe-de-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
    }

    #[DataProvider('ecoles')]
    public function test_une_connexion_porte_l_ecole_du_compte(InstitutionMode $mode): void
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        $compte = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'enseignant',
            'email' => 'prof@ecole.test',
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => null,
        ]);

        $this->postJson('/api/auth/login', ['username' => 'prof@ecole.test', 'password' => self::MOT_DE_PASSE])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $compte->id, 'institution_id' => $ecole->id]);
    }

    /**
     * Le login local d'un compte rattaché à KLASSCI rafraîchit sa session
     * KLASSCI puis passe par `LoginOrchestrator::buildKlassciSuccessResponse` :
     * c'est le chemin normal des élèves et enseignants d'une école KLASSCI.
     */
    public function test_la_connexion_d_un_compte_klassci_porte_son_ecole(): void
    {
        $ecole = Institution::factory()->create([
            'slug' => 'presentation',
            'klassci_api_url' => 'https://presentation.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $compte = User::factory()->for($ecole)->teacher()->create([
            'email' => 'teacher@klassci.test',
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => 200001,
            'klassci_token' => 'jeton-expire',
        ]);
        $this->mock(KlassciAuthClient::class, fn ($mock) => $mock->shouldReceive('attemptLogin')->once()->andReturn([
            'data' => ['user' => ['id' => 200001, 'email' => $compte->email, 'nom' => $compte->name, 'role' => 'enseignant'], 'token' => 'jeton-frais'],
            'meta' => [],
        ]));
        $this->mock(KlassciUserSynchronizer::class, fn ($mock) => $mock->shouldReceive('sync')->once()->andReturn($compte));

        // Le chemin KLASSCI, et non le repli local qui écrirait la même ligne.
        $this->postJson('/api/auth/login', ['username' => 'teacher@klassci.test', 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('meta.klassci_synced', true);

        $entree = AuditLog::query()->withoutGlobalScope('institution')->where('action', 'login')->sole();
        self::assertSame($compte->id, $entree->user_id);
        self::assertSame($ecole->id, $entree->institution_id);
        self::assertSame('klassci', $entree->after['method'] ?? null);
    }

    public function test_un_echec_de_connexion_n_a_pas_d_ecole(): void
    {
        $this->mock(KlassciTenantDiscovery::class, fn ($mock) => $mock->shouldReceive('findMatchingTenants')->andReturn([
            ['code' => 'ecole', 'api_base_url' => 'https://ecole.klassci.test/api/lms'],
        ]));
        $this->mock(KlassciAuthClient::class, fn ($mock) => $mock->shouldReceive('attemptLogin')->andReturnNull());

        $this->postJson('/api/auth/login', ['username' => 'inconnu@ecole.test', 'password' => 'mauvais-mot-de-passe'])->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'user_id' => null, 'institution_id' => null]);
    }

    public function test_une_deconnexion_porte_l_ecole_du_compte(): void
    {
        $ecole = Institution::factory()->create();
        $compte = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        $this->asTenant($compte)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $compte->id, 'institution_id' => $ecole->id]);
    }

    public function test_le_supradmin_qui_change_le_mode_ou_supprime_une_ecole_ecrit_l_ecole_visee(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        // Une école ne se supprime qu'une fois désactivée (InstitutionCrudService::softDelete).
        $fermee = Institution::factory()->create(['is_active' => false]);
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->asTenant($supradmin)->patchJson("/api/admin/institutions/{$ecole->id}/mode", ['mode' => 'standalone'])->assertOk();
        $this->asTenant($supradmin)->deleteJson("/api/admin/institutions/{$fermee->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.mode_changed', 'user_id' => $supradmin->id, 'institution_id' => $ecole->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.soft_deleted', 'user_id' => $supradmin->id, 'institution_id' => $fermee->id]);
    }

    /** La commande de purge (aucun acteur) écrit l'école du compte purgé ; l'entrée survit à la purge. */
    public function test_une_purge_systeme_ecrit_l_ecole_visee(): void
    {
        $ecole = Institution::factory()->create();
        $compte = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);
        $compte->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('purge:run', ['domaine' => 'users', '--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $compte->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.purged', 'user_id' => null, 'institution_id' => $ecole->id]);
    }

    /** Un acteur de l'école A dont l'action vise un modèle de l'école B garde l'école A : la cible ne l'écrase jamais. */
    public function test_la_cible_n_ecrase_jamais_l_ecole_de_l_acteur(): void
    {
        $ecoleA = Institution::factory()->create();
        $ecoleB = Institution::factory()->standalone()->create();
        $acteur = User::factory()->create(['institution_id' => $ecoleA->id, 'role' => 'admin']);
        $chapitreB = Chapter::factory()->create(['institution_id' => $ecoleB->id]);
        Auth::guard()->setUser($acteur);

        app(AuditLogger::class)->logSecurityEvent('chapter.purged', $chapitreB);
        app(AuditLogger::class)->logSecurityEvent('institution.mode_changed', $ecoleB);

        self::assertSame(
            [$ecoleA->id, $ecoleA->id],
            AuditLog::query()->withoutGlobalScope('institution')->where('user_id', $acteur->id)->orderBy('id')->pluck('institution_id')->all(),
        );
    }
}
