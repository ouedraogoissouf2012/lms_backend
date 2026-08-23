<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #503 — la résolution des participants dans `VideoSessionAttendancesSyncer`
 * (users + inscription) doit émettre un nombre de requêtes CONSTANT quel que
 * soit le nombre de participants (pattern « baseline vs afterGrowth »).
 *
 * On ne compte QUE les requêtes de résolution (`users` / `user_classes` /
 * `classes`) ; les écritures `esbtp_attendances` (`updateOrCreate`) sont
 * légitimement par-participant et exclues du comptage.
 *
 * @see app/Services/Attendances/VideoSessionAttendancesSyncer.php
 */
final class AttendancesSyncNoNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;

    /**
     * #608 — l'id de la séance est lu, jamais supposé : InnoDB ne rollback pas
     * son compteur `AUTO_INCREMENT` entre deux tests `RefreshDatabase` (MySQL
     * 8.4 §17.6.1.6), contrairement au `rowid` SQLite qui repart à 1. Un
     * `seance_cours_id => 1` en dur donnait donc 404 dès que ce test n'était
     * plus le premier du processus.
     */
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 777,
            'klassci_token' => 'test-token',
        ]);

        $classe = Classe::factory()->for($this->institution)->create(['klassci_id' => 55]);

        // 6 étudiants inscrits (klassci_id 100..105) — assez pour tester 2 vs 5.
        foreach (range(100, 105) as $klassciId) {
            $student = User::factory()->student()->for($this->institution)->create([
                'klassci_id' => $klassciId,
            ]);
            $classe->etudiants()->attach($student->id, ['statut' => 'actif']);
        }

        $this->seance = Seance::factory()->for($this->institution)->create([
            'klassci_enseignant_id' => 777,
            'klassci_classe_id' => 55,
        ]);
    }

    public function test_resolution_query_count_is_constant_as_participants_grow(): void
    {
        Sanctum::actingAs($this->teacher);

        $baseline = $this->countResolutionQueries(fn () => $this->syncWith([100, 101]));
        $afterGrowth = $this->countResolutionQueries(fn () => $this->syncWith([100, 101, 102, 103, 104]));

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté : {$baseline} requêtes de résolution à 2 participants vs {$afterGrowth} à 5."
        );
    }

    /**
     * @param  array<int, int>  $klassciIds
     */
    private function syncWith(array $klassciIds): void
    {
        $participants = array_map(fn (int $id): array => [
            'etudiant_id' => $id,
            'statut' => 'present',
        ], $klassciIds);

        $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => $this->seance->id,
            'date' => now()->format('Y-m-d'),
            'participants' => $participants,
        ])->assertStatus(200);
    }

    /**
     * Compte les requêtes touchant `users` / `user_classes` / `classes` pendant
     * l'exécution de $run (exclut les écritures `esbtp_attendances`).
     */
    private function countResolutionQueries(callable $run): int
    {
        DB::enableQueryLog();
        $run();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool =>
            str_contains($q, '"users"') || str_contains($q, '`users`')
            || str_contains($q, 'user_classes')
            || str_contains($q, '"classes"') || str_contains($q, '`classes`')
        )->count();
    }
}
