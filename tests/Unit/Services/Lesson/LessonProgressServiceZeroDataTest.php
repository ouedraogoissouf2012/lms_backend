<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lesson;

use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\Lesson\LessonProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #368 — garde zéro-division de `LessonProgressService::averageCompletionRate`
 * (`$totalCount > 0`, app/Services/Lesson/LessonProgressService.php:33).
 *
 * « Un cours sans progression » : une leçon jamais commencée par personne doit
 * produire un taux de 0.0, jamais une DivisionByZeroError → 500.
 */
final class LessonProgressServiceZeroDataTest extends TestCase
{
    use RefreshDatabase;

    private LessonProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LessonProgressService;
    }

    public function test_lecon_sans_aucune_progression_donne_taux_zero_pas_une_division_par_zero(): void
    {
        $lesson = Lesson::factory()->create();

        $rate = $this->service->averageCompletionRate($lesson);

        self::assertSame(0.0, $rate);
    }

    public function test_lecon_avec_progressions_calcule_le_taux_reel(): void
    {
        $lesson = Lesson::factory()->create();
        LessonProgress::factory()->create(['lesson_id' => $lesson->id, 'status' => 'completed']);
        LessonProgress::factory()->create(['lesson_id' => $lesson->id, 'status' => 'in_progress']);

        $rate = $this->service->averageCompletionRate($lesson);

        self::assertSame(50.0, $rate);
    }
}
