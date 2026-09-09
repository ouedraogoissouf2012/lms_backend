<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Jobs\SyncUserClasses;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MyMatieresQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Charger ses matières enregistre le lien enseignant ↔ matière (#712).
 *
 * ## Pourquoi ICI, et pas au login
 *
 * Le déclencheur était au login. Mesure en production le 2026-09-09, à chaque
 * reconnexion :
 *
 *     production.ERROR: Erreur sync matières au login
 *       error: "URL de base KLASSCI absente ou invalide
 *               (config services.klassci.url / KLASSCI_API_URL)."
 *
 * `KlassciConfigResolver` résout l'URL amont en trois priorités : le jeton
 * personnel de l'utilisateur AUTHENTIFIÉ (1), son institution (2), la config
 * globale (3). Pendant le login, aucun utilisateur Sanctum n'existe encore :
 * les deux premières sont hors d'atteinte et la troisième lit une
 * configuration globale qui n'a pas de sens en multi-tenant — chaque
 * établissement a son propre serveur KLASSCI, renseigné en base.
 *
 * **Le login est structurellement incapable de résoudre cette URL.** Y placer
 * une synchronisation KLASSCI était l'erreur de conception ; le correctif
 * précédent était donc inerte, avec des tests verts et une CI verte.
 *
 * Ce chemin-ci est AUTHENTIFIÉ (`auth:sanctum` sur `/lms/teacher/my-matieres`).
 * La priorité 1 s'applique, l'appel aboutit — l'écran « Mes Matières » du
 * tableau de bord le prouve en production — et le service tient déjà en main
 * la liste exacte des matières de l'enseignant.
 *
 * On enregistre là où la vérité est disponible.
 */
final class MyMatieresRecordsTeacherLinkTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => 'jeton-enseignant',
            'klassci_enseignant_id' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE défaut corrigé : consulter ses matières écrit le lien.
     */
    public function test_loading_my_matieres_records_the_teacher_link(): void
    {
        Queue::fake();
        $this->fakeDashboard([11, 12]);

        app(MyMatieresQueryService::class)->getMatieresForUser($this->teacher);

        $liens = DB::table('matiere_enseignant')
            ->where('klassci_enseignant_id', 9)
            ->where('institution_id', $this->institution->id)
            ->where('status', 'active')
            ->pluck('klassci_matiere_id')
            ->all();

        sort($liens);
        self::assertSame([11, 12], $liens);
    }

    /**
     * Le second maillon : la synchro des classes part en file, depuis un
     * chemin authentifié. Le job pose son propre tenant, il n'a donc pas
     * besoin d'un utilisateur connecté pour résoudre l'URL.
     */
    public function test_it_dispatches_the_classes_sync(): void
    {
        Queue::fake();
        $this->fakeDashboard([11]);

        app(MyMatieresQueryService::class)->getMatieresForUser($this->teacher);

        Queue::assertPushed(
            SyncUserClasses::class,
            fn (SyncUserClasses $job): bool => $job->userId === $this->teacher->id
                && $job->institutionId === (int) $this->institution->id,
        );
    }

    /**
     * L'INVARIANT hérité du linker : une liste vide ne désactive rien. KLASSCI
     * qui ne répond rien n'est pas KLASSCI qui dit « plus aucune matière ».
     */
    public function test_an_empty_dashboard_never_erases_existing_links(): void
    {
        Queue::fake();
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => 11,
            'klassci_enseignant_id' => 9,
            'status' => 'active',
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeDashboard([]);
        app(MyMatieresQueryService::class)->getMatieresForUser($this->teacher);

        self::assertSame(1, DB::table('matiere_enseignant')->where('status', 'active')->count());
    }

    /**
     * @param  list<int>  $klassciMatiereIds
     */
    private function fakeDashboard(array $klassciMatiereIds): void
    {
        $matieres = array_map(
            static fn (int $id): array => ['id' => $id, 'nom' => 'Matière '.$id, 'coefficient' => 1],
            $klassciMatiereIds,
        );

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matieres): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton-enseignant', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => $matieres, 'evaluations' => []]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }
}
