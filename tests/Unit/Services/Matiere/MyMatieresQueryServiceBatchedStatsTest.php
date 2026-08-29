<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Matiere;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MyMatieresQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #546 — `MyMatieresQueryService::enrichMatiere()` doit produire un nombre
 * de requêtes DB CONSTANT quel que soit le nombre de matières KLASSCI
 * (avant fix : 3 requêtes/matière — publiées, brouillons, séances).
 *
 * @see app/Services/Matiere/MyMatieresQueryService.php
 */
final class MyMatieresQueryServiceBatchedStatsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test-token',
        ]);
    }

    public function test_query_count_is_constant_as_matiere_count_grows(): void
    {
        $baseline = $this->countStatsQueries(matiereIds: [1, 2]);
        $afterGrowth = $this->countStatsQueries(matiereIds: [10, 11, 12, 13, 14, 15]);

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté : {$baseline} requêtes pour 2 matières vs {$afterGrowth} pour 6."
        );
    }

    public function test_statistics_are_correct_per_matiere(): void
    {
        // Matière 1 : 2 leçons publiées, 1 brouillon, 3 séances.
        Lesson::factory()->forTeacher($this->teacher)->published()->count(2)->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => 1,
        ]);
        Lesson::factory()->forTeacher($this->teacher)->draft()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => 1,
        ]);
        Seance::factory()->forInstitution($this->institution)->count(3)->create([
            'klassci_matiere_id' => 1,
        ]);

        // Matière 2 : rien (matière "vide" — doit renvoyer des 0, pas planter).
        // Bruit matière 3, non retournée par KLASSCI dans ce test — ne doit
        // jamais fuiter dans les stats de 1 ou 2.
        Lesson::factory()->forTeacher($this->teacher)->published()->count(5)->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => 3,
        ]);

        $matieres = $this->fetchMatieres(matiereIds: [1, 2]);

        $matiere1 = collect($matieres)->firstWhere('id', 1);
        $matiere2 = collect($matieres)->firstWhere('id', 2);

        self::assertSame(2, $matiere1['statistiques']['nombre_lessons_publiees']);
        self::assertSame(1, $matiere1['statistiques']['nombre_lessons_brouillons']);
        self::assertSame(3, $matiere1['statistiques']['nombre_seances']);

        self::assertSame(0, $matiere2['statistiques']['nombre_lessons_publiees']);
        self::assertSame(0, $matiere2['statistiques']['nombre_lessons_brouillons']);
        self::assertSame(0, $matiere2['statistiques']['nombre_seances']);
    }

    /**
     * @param  array<int, int>  $matiereIds
     */
    private function fetchMatieres(array $matiereIds): array
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matiereIds): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn([
                'data' => [
                    'matieres' => array_map(fn (int $id): array => ['id' => $id, 'libelle' => "Matiere {$id}"], $matiereIds),
                    'evaluations' => [],
                ],
            ]);
        });

        $service = $this->app->make(MyMatieresQueryService::class);

        return $service->getMatieresForUser($this->teacher);
    }

    /**
     * @param  array<int, int>  $matiereIds
     */
    private function countStatsQueries(array $matiereIds): int
    {
        foreach ($matiereIds as $id) {
            Lesson::factory()->forTeacher($this->teacher)->published()->create([
                'institution_id' => $this->institution->id,
                'matiere_id' => $id,
            ]);
            Seance::factory()->forInstitution($this->institution)->create([
                'klassci_matiere_id' => $id,
            ]);
        }

        DB::enableQueryLog();
        $this->fetchMatieres($matiereIds);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool =>
            str_contains($q, '"lessons"') || str_contains($q, '`lessons`')
            || str_contains($q, '"seances"') || str_contains($q, '`seances`')
        )->count();
    }
}
