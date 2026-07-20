<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Jobs\SyncKlassciSeances;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #473 — Isolation multi-tenant des séances (§1.2 / §1.3).
 *
 * Racine du défaut : `klassci_seance_id` portait un `unique()` GLOBAL, alors que
 * deux institutions ont des backends KLASSCI indépendants et peuvent légitimement
 * posséder le même `klassci_seance_id`. De plus, `SyncKlassciSeances` s'exécute
 * hors contexte HTTP : le scope global `BelongsToInstitution` y est inerte
 * (aucun tenant résolu), donc `institution_id` doit être écrit EXPLICITEMENT à
 * partir de `$teacher->institution_id`, et les lookups/archivage doivent être
 * scopés explicitement — jamais via le scope global.
 *
 * Ces tests reproduisent le job réel (tenant-less) avec des enseignants de deux
 * institutions synchronisant le MÊME `klassci_seance_id`.
 */
final class SeanceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * Deux enseignants de deux institutions distinctes synchronisent tous deux
     * `klassci_seance_id = 42`. Attendu : DEUX lignes distinctes, chacune portant
     * son propre `institution_id`, sans collision ni écrasement.
     */
    public function test_same_klassci_seance_id_creates_isolated_rows_per_institution(): void
    {
        $schoolA = Institution::factory()->create(['slug' => 'school-a']);
        $schoolB = Institution::factory()->create(['slug' => 'school-b']);

        $teacherA = User::factory()->for($schoolA)->create([
            'role' => 'enseignant',
            'name' => 'Prof A',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);
        $teacherB = User::factory()->for($schoolB)->create([
            'role' => 'enseignant',
            'name' => 'Prof B',
            'klassci_id' => 2002,
            'klassci_token' => 'token-b',
        ]);

        $this->mockKlassciForCollidingSeance($teacherA, $teacherB);

        // Le job s'exécute SANS tenant résolu (comme en production sur la queue).
        app(TenantManager::class)->reset();
        $this->runSyncJob();

        // Deux lignes physiques distinctes malgré le même klassci_seance_id.
        self::assertSame(
            2,
            Seance::withoutGlobalScope('institution')->where('klassci_seance_id', 42)->count(),
            'Le même klassci_seance_id doit produire une ligne par institution, pas une collision.',
        );

        $rowA = Seance::withoutGlobalScope('institution')
            ->where('institution_id', $schoolA->id)
            ->where('klassci_seance_id', 42)
            ->first();
        $rowB = Seance::withoutGlobalScope('institution')
            ->where('institution_id', $schoolB->id)
            ->where('klassci_seance_id', 42)
            ->first();

        self::assertNotNull($rowA, "L'institution A doit avoir sa propre séance 42.");
        self::assertNotNull($rowB, "L'institution B doit avoir sa propre séance 42.");

        // Aucun écrasement croisé : chaque ligne porte les données de SON tenant.
        self::assertSame('Prof A', $rowA->enseignant_nom);
        self::assertSame('Prof B', $rowB->enseignant_nom);
        self::assertSame($teacherA->id, $rowA->created_by);
        self::assertSame($teacherB->id, $rowB->created_by);
    }

    /**
     * L'archivage d'un tenant ne doit jamais toucher les séances d'un autre tenant.
     * Ici, la séance de l'institution B (klassci_seance_id absent du sync de A) ne
     * doit PAS être archivée lorsque seul A est synchronisé.
     */
    public function test_archival_does_not_cross_tenants(): void
    {
        $schoolA = Institution::factory()->create(['slug' => 'school-a']);
        $schoolB = Institution::factory()->create(['slug' => 'school-b']);

        // Séance préexistante de B, active, hors du sync de A.
        $seanceB = Seance::factory()->forInstitution($schoolB)->create([
            'klassci_seance_id' => 99,
            'is_active' => true,
        ]);

        $teacherA = User::factory()->for($schoolA)->create([
            'role' => 'enseignant',
            'name' => 'Prof A',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        // A synchronise uniquement sa séance 42 (99 n'apparaît jamais côté A).
        $this->mockKlassciForSingleTeacher('token-a', 42, 'Prof A matiere');

        app(TenantManager::class)->reset();
        $this->runSyncJob();

        $seanceB->refresh();
        self::assertTrue(
            $seanceB->is_active,
            "La séance de l'institution B ne doit pas être archivée par le sync de A.",
        );
    }

    /**
     * Chemin HTTP (TeachingSeancesFetcher) : contrairement au job, il tourne avec
     * un tenant résolu (middleware ResolveInstitution → TenantManager). Le scope
     * global `institution` filtre alors les lookups et le hook `creating` fixe
     * `institution_id`. Ce test prouve que deux enseignants de deux institutions
     * synchronisant le même klassci_seance_id via le fetcher restent isolés.
     */
    public function test_teaching_fetcher_isolates_same_seance_id_across_tenants(): void
    {
        $schoolA = Institution::factory()->create(['slug' => 'school-a']);
        $schoolB = Institution::factory()->create(['slug' => 'school-b']);

        $teacherA = User::factory()->for($schoolA)->create([
            'role' => 'enseignant', 'name' => 'Prof A', 'klassci_id' => 1001, 'klassci_token' => 'token-a',
        ]);
        $teacherB = User::factory()->for($schoolB)->create([
            'role' => 'enseignant', 'name' => 'Prof B', 'klassci_id' => 2002, 'klassci_token' => 'token-b',
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $this->stubTeacherDashboard($mock, 'token-a', 42, 'Matiere A', 'Classe A', 501);
            $this->stubTeacherDashboard($mock, 'token-b', 42, 'Matiere B', 'Classe B', 502);
        });

        $tenant = app(TenantManager::class);
        $fetcher = app(\App\Services\Seances\TeachingSeancesFetcher::class);

        $tenant->set($schoolA);
        $fetcher->fetch($teacherA, 'token-a');

        $tenant->set($schoolB);
        $fetcher->fetch($teacherB, 'token-b');

        self::assertSame(
            2,
            Seance::withoutGlobalScope('institution')->where('klassci_seance_id', 42)->count(),
            'Le fetcher enseignant doit isoler la séance 42 par institution.',
        );
        self::assertSame(
            $schoolA->id,
            Seance::withoutGlobalScope('institution')->where('klassci_enseignant_id', 1001)->value('institution_id'),
        );
        self::assertSame(
            $schoolB->id,
            Seance::withoutGlobalScope('institution')->where('klassci_enseignant_id', 2002)->value('institution_id'),
        );
    }

    /**
     * Deux institutions partagent le MÊME klassci_classe_id (espaces d'ID KLASSCI
     * indépendants). Quand le job synchronise l'institution A, seuls les étudiants
     * de A doivent être notifiés — jamais ceux de B (#473, volet notifications).
     */
    public function test_notifications_do_not_leak_across_tenants(): void
    {
        $schoolA = Institution::factory()->create(['slug' => 'school-a']);
        $schoolB = Institution::factory()->create(['slug' => 'school-b']);

        // Même klassci_id de classe des deux côtés, chacune avec son étudiant.
        $classeA = \App\Models\Classe::factory()->for($schoolA)->create(['klassci_id' => 700]);
        $studentA = User::factory()->for($schoolA)->create(['role' => 'etudiant']);
        $classeA->etudiants()->attach($studentA->id, ['statut' => 'actif']);

        $classeB = \App\Models\Classe::factory()->for($schoolB)->create(['klassci_id' => 700]);
        $studentB = User::factory()->for($schoolB)->create(['role' => 'etudiant']);
        $classeB->etudiants()->attach($studentB->id, ['statut' => 'actif']);

        $teacherA = User::factory()->for($schoolA)->create([
            'role' => 'enseignant', 'name' => 'Prof A', 'klassci_id' => 1001, 'klassci_token' => 'token-a',
        ]);

        // A synchronise une séance sur la classe klassci_id=700.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Matiere A']]]);
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres/10', 'GET')
                ->andReturn(['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                    'classe' => ['id' => 700, 'nom' => 'Classe A'],
                ]]]]);
            $this->stubQuietClasseSync($mock);
        });

        app(TenantManager::class)->reset();
        $this->runSyncJob();

        $notifiedUserIds = \App\Models\Notification::withoutGlobalScope('institution')
            ->pluck('user_id')
            ->all();

        self::assertContains($studentA->id, $notifiedUserIds, "L'étudiant de l'institution A doit être notifié.");
        self::assertNotContains($studentB->id, $notifiedUserIds, "L'étudiant de l'institution B ne doit JAMAIS être notifié par le sync de A.");
    }

    private function runSyncJob(): void
    {
        (new SyncKlassciSeances)->handle(
            app(KlassciProxyService::class),
            app(ClasseSyncService::class),
            app(NotificationService::class),
            app(LoggerInterface::class),
            app(\App\Services\Seances\SeanceCacheDataBuilder::class),
        );
    }

    /**
     * Mock KLASSCI : deux enseignants, chacun voyant une matière contenant la
     * même séance klassci_seance_id=42 dans son propre backend.
     */
    private function mockKlassciForCollidingSeance(User $teacherA, User $teacherB): void
    {
        // Seul KlassciProxyService est mocké (il n'est pas final). Les services
        // annexes (ClasseSyncService, NotificationService — marqués `final`)
        // tournent réels : leurs appels réseau passent par ce même mock, et sur
        // la base de test vide `notifyVisioScheduled` ne trouve aucun étudiant
        // (retourne 0). C'est le pattern de ClasseSyncServiceTest.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $this->stubTeacherSeance($mock, 'token-a', 42, 'Matiere A', 'Classe A', 501);
            $this->stubTeacherSeance($mock, 'token-b', 42, 'Matiere B', 'Classe B', 502);
            $this->stubQuietClasseSync($mock);
        });
    }

    private function mockKlassciForSingleTeacher(string $token, int $seanceId, string $matiereNom): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($token, $seanceId, $matiereNom): void {
            $this->stubTeacherSeance($mock, $token, $seanceId, $matiereNom, 'Classe A', 501);
            $this->stubQuietClasseSync($mock);
        });
    }

    /**
     * Stub la paire d'appels KLASSCI (liste matières + détail matière avec séances)
     * pour un token donné.
     */
    private function stubTeacherSeance(
        MockInterface $mock,
        string $token,
        int $seanceId,
        string $matiereNom,
        string $classeNom,
        int $classeId,
    ): void {
        $mock->shouldReceive('requestWithUserToken')
            ->with($token, 'matieres', 'GET')
            ->andReturn(['data' => [['id' => 10, 'nom' => $matiereNom]]]);

        $mock->shouldReceive('requestWithUserToken')
            ->with($token, 'matieres/10', 'GET')
            ->andReturn(['data' => ['seances_programmees' => [[
                'id' => $seanceId,
                'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                'classe' => ['id' => $classeId, 'nom' => $classeNom],
            ]]]]);
    }

    /**
     * Stub le flux de TeachingSeancesFetcher pour un token : teacher-dashboard
     * (matières) + batch séances + batch effectifs de classe.
     */
    private function stubTeacherDashboard(
        MockInterface $mock,
        string $token,
        int $seanceId,
        string $matiereNom,
        string $classeNom,
        int $classeId,
    ): void {
        $mock->shouldReceive('requestWithUserToken')
            ->with($token, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [['id' => 10, 'nom' => $matiereNom]]]]);

        $mock->shouldReceive('fetchManyMatieresDetails')
            ->with([10], $token)
            ->andReturn([10 => ['data' => ['seances_programmees' => [[
                'id' => $seanceId,
                'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                'classe' => ['id' => $classeId, 'nom' => $classeNom],
            ]]]]]);

        $mock->shouldReceive('fetchManyClassesDetails')
            ->with([$classeId], $token)
            ->andReturn([$classeId => ['data' => ['classe' => ['places_occupees' => 20]]]]);
    }

    /**
     * ClasseSyncService (réel) appelle `classes/{id}` lors du sync — on renvoie un
     * payload minimal pour qu'il n'échoue pas, sans intérêt pour l'isolation testée.
     */
    private function stubQuietClasseSync(MockInterface $mock): void
    {
        $mock->shouldReceive('requestWithUserToken')
            ->with(\Mockery::any(), \Mockery::pattern('#^classes/\d+$#'), 'GET')
            ->andReturn(['data' => ['classe' => ['id' => 0, 'libelle' => 'stub']]]);
        $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
    }
}
