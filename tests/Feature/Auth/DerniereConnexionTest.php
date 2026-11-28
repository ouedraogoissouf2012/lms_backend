<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1704 : `users.last_login_at`, la dernière connexion d'un compte, écrite par la connexion
 * réussie dans les deux volets, et par elle seule. Elle ne touche pas `updated_at` : une
 * connexion n'est pas une modification du compte.
 */
final class DerniereConnexionTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MAINTENANT = '2026-11-28 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::MAINTENANT);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_connexion_locale_date_le_compte_sans_toucher_sa_modification(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $eleve->forceFill(['password' => Hash::make('bon-mot-de-passe')])->save();
        DB::table('users')->where('id', $eleve->id)->update(['updated_at' => '2026-01-01 08:00:00']);

        $this->postJson('/api/auth/login', ['username' => $eleve->email, 'password' => 'bon-mot-de-passe'])->assertOk();

        $ligne = DB::table('users')->where('id', $eleve->id)->first(['last_login_at', 'updated_at']);
        self::assertNotNull($ligne);
        self::assertSame(self::MAINTENANT, Carbon::parse($ligne->last_login_at)->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-01 08:00:00', Carbon::parse($ligne->updated_at)->format('Y-m-d H:i:s'));
    }

    public function test_un_echec_de_connexion_ne_date_rien(): void
    {
        $eleve = $this->membre($this->ecole(), 'etudiant');
        $eleve->forceFill(['password' => Hash::make('bon-mot-de-passe')])->save();
        $this->mock(KlassciTenantDiscovery::class, fn ($mock) => $mock->shouldReceive('findMatchingTenants')->andReturn([]));

        $this->postJson('/api/auth/login', ['username' => $eleve->email, 'password' => 'mauvais-mot-de-passe'])->assertUnauthorized();

        self::assertNull(User::query()->findOrFail($eleve->id)->last_login_at);
    }

    /**
     * La reprise du déploiement : le jeton le plus récent d'un compte est sa dernière connexion ; un
     * compte sans jeton reste sans connexion enregistrée, sans date fabriquée.
     */
    public function test_la_reprise_date_par_le_jeton_le_plus_recent_et_rien_sans_jeton(): void
    {
        $migration = require database_path('migrations/2026_11_28_010000_add_last_login_at_to_users.php');
        $ecole = $this->ecole();
        $connecte = $this->membre($ecole, 'etudiant');
        $sansJeton = $this->membre($ecole, 'etudiant');
        Carbon::setTestNow('2026-11-20 08:00:00');
        $connecte->createToken('ancien');
        Carbon::setTestNow('2026-11-25 09:30:00');
        $connecte->createToken('recent');
        Carbon::setTestNow(self::MAINTENANT);

        $migration->down();
        $migration->up();

        self::assertSame('2026-11-25 09:30:00', User::query()->findOrFail($connecte->id)->last_login_at?->format('Y-m-d H:i:s'));
        self::assertNull(User::query()->findOrFail($sansJeton->id)->last_login_at);
    }

    public function test_la_connexion_klassci_date_le_compte(): void
    {
        $ecole = Institution::factory()->create(['slug' => 'presentation', 'klassci_api_url' => 'https://presentation.klassci.test/api/lms', 'is_active' => true]);
        $enseignant = User::factory()->for($ecole)->teacher()->create([
            'email' => 'teacher@klassci.test', 'password' => Hash::make('correct-password'),
            'klassci_id' => 200001, 'klassci_token' => 'expired-token',
        ]);
        $this->mock(KlassciAuthClient::class, fn ($mock) => $mock->shouldReceive('attemptLogin')->once()->andReturn([
            'data' => ['user' => ['id' => 200001, 'email' => $enseignant->email, 'nom' => $enseignant->name, 'role' => 'enseignant'], 'token' => 'fresh-token'],
            'meta' => [],
        ]));
        $this->mock(KlassciUserSynchronizer::class, fn ($mock) => $mock->shouldReceive('sync')->once()->andReturn($enseignant));
        $this->mock(KlassciTenantDiscovery::class, fn ($mock) => $mock->shouldNotReceive('findMatchingTenants'));

        $this->postJson('/api/auth/login', ['username' => 'teacher@klassci.test', 'password' => 'correct-password'])
            ->assertOk()->assertJsonPath('meta.klassci_synced', true);

        self::assertSame(self::MAINTENANT, User::query()->findOrFail($enseignant->id)->last_login_at?->format('Y-m-d H:i:s'));
    }
}
