<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\UpcomingSeancesFetcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Issue #476 — Non-régression fonctionnelle STRICTE du chemin « séances à venir »
 * après le pré-chargement anti-N+1. Le refactor est purement structurel : mêmes
 * séances filtrées (archivées / masquées), mêmes champs visio, isolation tenant
 * préservée. Indépendant du comptage de requêtes (couvert par le test perf dédié).
 */
final class UpcomingSeancesFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_archived_seance_is_hidden_from_student_but_visible_to_teacher(): void
    {
        $this->localSeance(101, active: true);
        $this->localSeance(102, active: false); // archivée

        $studentResult = $this->fetchFor('etudiant', [101, 102]);
        self::assertEqualsCanonicalizing([101], $this->ids($studentResult), 'Étudiant : la séance archivée 102 est masquée.');

        $teacherResult = $this->fetchFor('enseignant', [101, 102]);
        self::assertEqualsCanonicalizing([101, 102], $this->ids($teacherResult), 'Enseignant : voit tout, même archivée.');
    }

    public function test_hidden_seance_is_absent_only_for_the_concerned_student(): void
    {
        $s = $this->localSeance(201);

        $studentA = $this->userWithRole('etudiant');
        SeanceUserHidden::hide($s->id, $studentA->id);
        $studentB = $this->userWithRole('etudiant');

        $resultA = $this->runFetch($studentA, [201]);
        self::assertNotContains(201, $this->ids($resultA), "L'étudiant A qui a masqué 201 ne la voit plus.");

        $resultB = $this->runFetch($studentB, [201]);
        self::assertContains(201, $this->ids($resultB), "L'étudiant B (qui n'a rien masqué) voit 201.");
    }

    public function test_seance_without_local_row_stays_visible_with_default_visio(): void
    {
        // Aucune Seance locale pour klassci_seance_id=301.
        $result = $this->fetchFor('etudiant', [301]);

        self::assertContains(301, $this->ids($result), 'Séance sans ligne locale reste visible.');
        $seance = $result->firstWhere('id', 301);
        self::assertFalse($seance['visio_enabled'], 'Visio par défaut désactivée.');
        self::assertNull($seance['visio_room_id']);
    }

    public function test_visio_fields_are_enriched_from_local_seance(): void
    {
        $this->localSeance(401, active: true)->update([
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_room_id' => 'room-401',
            'visio_active' => true,
        ]);

        $result = $this->fetchFor('etudiant', [401]);
        $seance = $result->firstWhere('id', 401);

        self::assertTrue($seance['visio_enabled']);
        self::assertSame('jitsi', $seance['visio_type']);
        self::assertSame('room-401', $seance['visio_room_id']);
        self::assertTrue($seance['visio_active']);
    }

    public function test_same_klassci_id_in_another_tenant_is_not_resolved(): void
    {
        // Séance archivée dans une AUTRE institution, même klassci_seance_id.
        $otherInstitution = Institution::factory()->create();
        Seance::factory()->forInstitution($otherInstitution)->create([
            'klassci_seance_id' => 501,
            'is_active' => false,
        ]);
        // Dans l'institution courante, la 501 n'existe pas localement.

        $result = $this->fetchFor('etudiant', [501]);

        // La séance archivée de l'autre tenant ne doit PAS filtrer la nôtre :
        // le scope institution empêche de la résoudre → 501 reste visible.
        self::assertContains(501, $this->ids($result), "L'archivage d'un autre tenant ne masque pas la séance ici.");
    }

    // ───────────────────────── helpers ─────────────────────────

    private function userWithRole(string $role): User
    {
        // Pas de klassci_id fixe : l'unique composite (klassci_id, institution_id)
        // interdit deux users identiques dans la même institution.
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_token' => 'fake-token',
        ]);
    }

    private function localSeance(int $klassciSeanceId, bool $active = true): Seance
    {
        return Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => $klassciSeanceId,
            'klassci_classe_id' => 200,
            'is_active' => $active,
        ]);
    }

    /**
     * @param  list<int>  $klassciSeanceIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fetchFor(string $role, array $klassciSeanceIds): \Illuminate\Support\Collection
    {
        return $this->runFetch($this->userWithRole($role), $klassciSeanceIds);
    }

    /**
     * @param  list<int>  $klassciSeanceIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function runFetch(User $user, array $klassciSeanceIds): \Illuminate\Support\Collection
    {
        $payloads = array_map(fn (int $id): array => [
            'id' => $id,
            'classe' => ['id' => 200, 'nom' => 'Classe 200'],
            'programmation' => [
                'date' => '2026-08-15',
                'heure_debut' => '2026-08-15T09:00:00.000000Z',
                'heure_fin' => '2026-08-15T10:00:00.000000Z',
                'salle' => 'Salle 1',
            ],
        ], $klassciSeanceIds);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payloads): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 42, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([42], 'fake-token')
                ->andReturn([42 => ['data' => ['seances_programmees' => $payloads]]]);
        });

        return app(UpcomingSeancesFetcher::class)
            ->fetch($user, 'fake-token', '2026-08-01', '2026-08-31', null, null);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $result
     * @return list<int>
     */
    private function ids(\Illuminate\Support\Collection $result): array
    {
        return $result->pluck('id')->all();
    }
}
