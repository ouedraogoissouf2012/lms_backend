<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Dashboard\TeacherStatsService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests unitaires de {@see TeacherStatsService} (issue #364 — TDD :
 * écrits AVANT l'extraction de la logique hors de
 * `TeacherStatsController::getStats`).
 *
 * Les leçons restent comptées via `lessons.enseignant_id`; les évaluations
 * passent par les colonnes `klassci_*` réelles (#401).
 */
final class TeacherStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_TEACHER_ID = 4242;

    private TeacherStatsService $service;

    private Institution $institutionA;

    private Institution $institutionB;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TeacherStatsService;
        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_id' => self::KLASSCI_TEACHER_ID,
            'klassci_enseignant_id' => self::KLASSCI_TEACHER_ID,
        ]);

        $this->app->make(TenantManager::class)->set($this->institutionA);
    }

    public function test_counts_lessons_and_distinct_matieres_classes_of_the_teacher(): void
    {
        // 4 leçons keyées sur le klassci_id : matières {10, 20} (doublon 10,
        // un null ignoré), classes {5, 7} (doublon 5, un null ignoré).
        $this->createLessonForTeacher(['matiere_id' => 10, 'classe_id' => 5]);
        $this->createLessonForTeacher(['matiere_id' => 10, 'classe_id' => null]);
        $this->createLessonForTeacher(['matiere_id' => 20, 'classe_id' => 5]);
        $this->createLessonForTeacher(['matiere_id' => null, 'classe_id' => 7]);

        // Leçon d'un autre enseignant : jamais comptée.
        Lesson::factory()->create([
            'institution_id' => $this->institutionA->id,
            'enseignant_id' => self::KLASSCI_TEACHER_ID + 1,
            'matiere_id' => 99,
            'classe_id' => 99,
        ]);
        Evaluation::factory()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'klassci_enseignant_id' => self::KLASSCI_TEACHER_ID,
            'klassci_matiere_id' => 30,
            'klassci_classe_id' => 8,
        ]);
        Evaluation::factory()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_enseignant_id' => self::KLASSCI_TEACHER_ID + 1,
            'klassci_matiere_id' => 99,
            'klassci_classe_id' => 99,
        ]);

        $stats = $this->service->buildStats($this->teacher);

        $this->assertSame(
            [
                'matieres' => 3,
                'classes' => 3,
                'evaluations' => 2,
                'lessons' => 4,
            ],
            $stats
        );
    }

    public function test_teacher_without_content_gets_all_zero_counters(): void
    {
        $stats = $this->service->buildStats($this->teacher);

        $this->assertSame(
            [
                'matieres' => 0,
                'classes' => 0,
                'evaluations' => 0,
                'lessons' => 0,
            ],
            $stats
        );
    }

    public function test_evaluations_contribute_through_real_klassci_columns(): void
    {
        Evaluation::factory()->count(3)->create([
            'institution_id' => $this->institutionA->id,
            'klassci_enseignant_id' => self::KLASSCI_TEACHER_ID,
            'klassci_matiere_id' => 30,
            'klassci_classe_id' => 8,
        ]);
        $this->createLessonForTeacher(['matiere_id' => 10, 'classe_id' => 5]);

        $stats = $this->service->buildStats($this->teacher);

        $this->assertSame(3, $stats['evaluations']);
        $this->assertSame(2, $stats['matieres']);
        $this->assertSame(2, $stats['classes']);
        $this->assertSame(1, $stats['lessons']);
    }

    public function test_tenant_a_stats_ignore_identical_teacher_data_from_tenant_b(): void
    {
        $this->createLessonForTeacher(['matiere_id' => 10, 'classe_id' => 5]);
        // Même klassci enseignant_id, institution B : exclu par le scope.
        Lesson::factory()->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => self::KLASSCI_TEACHER_ID,
            'matiere_id' => 77,
            'classe_id' => 77,
        ]);

        $stats = $this->service->buildStats($this->teacher);

        $this->assertSame(1, $stats['lessons']);
        $this->assertSame(1, $stats['matieres']);
        $this->assertSame(1, $stats['classes']);
    }

    public function test_tenant_b_stats_only_see_tenant_b_lessons(): void
    {
        $teacherB = User::factory()->teacher()->create([
            'institution_id' => $this->institutionB->id,
            'klassci_id' => self::KLASSCI_TEACHER_ID,
            'klassci_enseignant_id' => self::KLASSCI_TEACHER_ID,
        ]);
        Lesson::factory()->count(2)->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => self::KLASSCI_TEACHER_ID,
            'matiere_id' => 40,
            'classe_id' => 9,
        ]);
        // Bruit côté A avec le même klassci_id.
        $this->createLessonForTeacher(['matiere_id' => 10, 'classe_id' => 5]);

        $this->app->make(TenantManager::class)->set($this->institutionB);

        $stats = $this->service->buildStats($teacherB);

        $this->assertSame(2, $stats['lessons']);
        $this->assertSame(1, $stats['matieres']);
        $this->assertSame(1, $stats['classes']);
    }

    /**
     * @param  array{matiere_id: int|null, classe_id: int|null}  $attributes
     */
    private function createLessonForTeacher(array $attributes): void
    {
        Lesson::factory()->create($attributes + [
            'institution_id' => $this->institutionA->id,
            'enseignant_id' => self::KLASSCI_TEACHER_ID,
        ]);
    }
}
