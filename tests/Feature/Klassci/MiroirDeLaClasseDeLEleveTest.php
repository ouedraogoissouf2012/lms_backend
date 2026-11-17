<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Jobs\SyncUserClasses;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\ClasseSyncService;
use App\Services\Enrollment\KlassciEnrollmentSource;
use App\Services\Klassci\Auth\StudentClassSynchronizer;
use App\Services\KlassciProxyService;
use App\Services\Seances\Sync\SyncTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * #886 (ADR-886-01) — la classe KLASSCI d'un élève a son miroir local.
 *
 * Au login, `StudentClassSynchronizer` écrit `user_classes` (#969). Sans classe
 * miroir dans `classes`, `KlassciEnrollmentSource::localClasseIdsFor` ne traduit
 * rien et rend `[]`. Le login demande alors le job EXISTANT `SyncUserClasses`,
 * sur la file `low`, et `ClasseSyncService` reste le seul écrivain de `classes`.
 *
 * Le faux KLASSCI reprend la forme MESURÉE de `me/dashboard` (#969) :
 * `data.classe = {id, name}`. `me/student-dashboard` n'a jamais été mesuré ; le
 * chemin de l'élève n'en dépend pas, et ne demande pas non plus la liste des
 * classes de l'école (`GET /classes`).
 */
final class MiroirDeLaClasseDeLEleveTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSE_KLASSCI = 7001;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_un_eleve_sans_miroir_demande_la_synchro_de_sa_classe(): void
    {
        Queue::fake();
        $ecole = Institution::factory()->create();
        $eleve = $this->eleve($ecole);

        $this->synchroniser($eleve);

        Queue::assertPushedOn('low', SyncUserClasses::class, static fn (SyncUserClasses $job): bool => $job->userId === $eleve->id && $job->institutionId === $ecole->id);
        Queue::assertPushed(SyncUserClasses::class, 1);
    }

    public function test_un_eleve_dont_la_classe_a_son_miroir_ne_demande_rien(): void
    {
        Queue::fake();
        $ecole = Institution::factory()->create();
        Classe::factory()->for($ecole)->create(['klassci_id' => self::CLASSE_KLASSCI]);
        $eleve = $this->eleve($ecole);

        $this->synchroniser($eleve);

        Queue::assertNotPushed(SyncUserClasses::class);
    }

    public function test_le_miroir_d_une_autre_ecole_ne_compte_pas(): void
    {
        Queue::fake();
        Classe::factory()->for(Institution::factory()->create())->create(['klassci_id' => self::CLASSE_KLASSCI]);
        $ecole = Institution::factory()->create();
        $eleve = $this->eleve($ecole);

        $this->synchroniser($eleve);

        Queue::assertPushed(SyncUserClasses::class, 1);
    }

    public function test_le_job_cree_le_miroir_depuis_la_forme_mesuree_et_la_source_le_lit(): void
    {
        // Une seule closure : `me/dashboard` rend la forme mesurée, tout le reste refuse.
        Http::fake(static fn (Request $requete) => str_contains($requete->url(), 'me/dashboard')
            ? Http::response(['success' => true, 'data' => ['classe' => ['id' => self::CLASSE_KLASSCI, 'name' => 'BTS 1']]])
            : Http::response(['message' => 'Accès refusé'], 403));
        $ecole = Institution::factory()->create();
        $eleve = $this->eleve($ecole);
        UserClass::query()->create([
            'user_id' => $eleve->id,
            'klassci_classe_id' => self::CLASSE_KLASSCI,
            'institution_id' => $ecole->id,
        ]);

        (new SyncUserClasses($eleve->id, (int) $ecole->id))->handle(
            app(ClasseSyncService::class),
            app(SyncTenantContext::class),
            app(LoggerInterface::class),
        );

        $miroir = Classe::withoutGlobalScopes()
            ->where('institution_id', $ecole->id)
            ->where('klassci_id', self::CLASSE_KLASSCI)
            ->first();
        self::assertNotNull($miroir, 'Le miroir de la classe de l\'élève n\'a pas été créé.');
        self::assertSame('BTS 1', $miroir->libelle);
        self::assertSame([$miroir->id], app(KlassciEnrollmentSource::class)->localClasseIdsFor($eleve->fresh() ?? $eleve));
        Http::assertNotSent(static fn (Request $requete): bool => str_ends_with(parse_url($requete->url(), PHP_URL_PATH) ?: '', '/classes'));
    }

    public function test_dans_une_ecole_autonome_le_login_de_l_eleve_ne_demande_aucune_synchro(): void
    {
        Queue::fake();
        $ecole = Institution::factory()->standalone()->create();
        $eleve = User::factory()->student()->for($ecole)->create([
            'password' => Hash::make('Motdepasse1!'),
            'klassci_id' => null,
            'klassci_token' => null,
        ]);

        $this->postJson('/api/auth/login', ['username' => $eleve->email, 'password' => 'Motdepasse1!'])
            ->assertOk();

        Queue::assertNotPushed(SyncUserClasses::class);
    }

    private function eleve(Institution $ecole): User
    {
        return User::factory()->student()->for($ecole)->create(['klassci_token' => 'jeton-eleve']);
    }

    /** Le login d'un élève KLASSCI : `me/dashboard` rend la forme mesurée (#969). */
    private function synchroniser(User $eleve): void
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $proxy->shouldReceive('requestWithUserToken')
            ->andReturn(['data' => ['classe' => ['id' => self::CLASSE_KLASSCI, 'name' => 'BTS 1']]]);
        $this->instance(KlassciProxyService::class, $proxy);

        app(StudentClassSynchronizer::class)->sync($eleve, 'jeton-eleve');
    }
}
