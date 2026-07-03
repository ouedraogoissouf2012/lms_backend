<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Chapter\LessonChapterProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #368 — garde zéro-division de `LessonChapterProgressCalculator`
 * (early return `$totalChapters === 0`,
 * app/Services/Chapter/LessonChapterProgressCalculator.php:31).
 *
 * « Un cours sans chapitre » : une leçon vide doit produire un pourcentage 0,
 * jamais une DivisionByZeroError → 500 (la division ligne 73 n'est atteinte
 * que si `$totalChapters > 0`).
 */
final class LessonChapterProgressCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private LessonChapterProgressCalculator $calculator;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new LessonChapterProgressCalculator;
        $this->student = User::factory()->student()->create();
    }

    public function test_lecon_sans_chapitre_donne_pourcentage_zero_pas_une_division_par_zero(): void
    {
        $lesson = Lesson::factory()->create();

        $result = $this->calculator->calculate($this->student->id, $lesson->id);

        self::assertSame([
            'total_chapters' => 0,
            'completed_chapters' => 0,
            'percentage' => 0,
            'chapters' => [],
        ], $result);
    }

    public function test_lecon_avec_chapitres_calcule_le_pourcentage_reel(): void
    {
        $lesson = Lesson::factory()->create();
        $done = Chapter::factory()->create(['lesson_id' => $lesson->id, 'order' => 1]);
        Chapter::factory()->create(['lesson_id' => $lesson->id, 'order' => 2]);
        ChapterProgress::create([
            'user_id' => $this->student->id,
            'chapter_id' => $done->id,
            'completed_at' => now(),
            'institution_id' => $lesson->institution_id,
        ]);

        $result = $this->calculator->calculate($this->student->id, $lesson->id);

        self::assertSame(2, $result['total_chapters']);
        self::assertSame(1, $result['completed_chapters']);
        self::assertSame(50.0, $result['percentage']);
    }
}
