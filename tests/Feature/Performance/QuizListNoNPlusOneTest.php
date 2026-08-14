<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #546 — `QuizCrudService::list()` doit émettre un nombre de requêtes
 * `quiz_attempts` CONSTANT quel que soit le nombre de quiz sur la page
 * paginée. Avant fix : jusqu'à 3 requêtes/quiz (`attemptsCountForUser` appelé
 * 2 fois — une directe, une via `canUserAttempt` — + `bestAttemptForUser`).
 *
 * Pattern « baseline vs afterGrowth » (cf. EvaluationResultsNoNPlusOneTest,
 * AttendancesSyncNoNPlusOneTest #503) : deux pages de tailles différentes
 * mesurées dans le même test, le total de requêtes `quiz_attempts` doit être
 * identique.
 *
 * @see app/Services/Quiz/QuizCrudService.php
 * @see app/Services/Quiz/QuizAccessService.php
 */
final class QuizListNoNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
    }

    public function test_query_count_is_constant_as_page_size_grows(): void
    {
        Sanctum::actingAs($this->student);

        $baseline = $this->countAttemptQueries(quizCount: 2);
        $afterGrowth = $this->countAttemptQueries(quizCount: 6);

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté : {$baseline} requêtes quiz_attempts pour 2 quiz vs {$afterGrowth} pour 6."
        );
    }

    private function countAttemptQueries(int $quizCount): int
    {
        $teacher = User::factory()->teacher()->for($this->institution)->create();

        foreach (range(1, $quizCount) as $i) {
            $quiz = Quiz::factory()->forTeacher($teacher)->create([
                'institution_id' => $this->institution->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'max_attempts' => 3,
            ]);
            QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->withScore(70)->create([
                'institution_id' => $this->institution->id,
            ]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/quizzes?per_page=' . $quizCount)->assertStatus(200);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool => str_contains($q, 'quiz_attempts'))->count();
    }
}
