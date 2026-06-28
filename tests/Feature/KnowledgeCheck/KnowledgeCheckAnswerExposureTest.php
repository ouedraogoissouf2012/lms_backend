<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sécurité — fuite de triche : les endpoints de lecture CRUD d'un KnowledgeCheck
 * (`index`, `show`, `getByChapter`, en `auth:sanctum` sans rôle) ne doivent PAS
 * exposer `correct_answer` / `explanation` aux étudiants. Le staff garde la vue
 * complète. Couvre {@see \App\Http\Resources\KnowledgeCheckResource}.
 */
final class KnowledgeCheckAnswerExposureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $student;
    private Chapter $chapter;
    private KnowledgeCheck $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->owner = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'enseignant_id' => $this->owner->id,
            'status' => 'published',
        ]);
        $this->chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $this->owner->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
        $this->quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $this->chapter->id,
            'institution_id' => $institution->id,
            'is_active' => true,
            'questions' => [
                [
                    'question' => 'Capitale du Burkina ?',
                    'type' => 'single',
                    'options' => ['Bobo', 'Ouaga'],
                    'correct_answer' => 'Ouaga',
                    'explanation' => 'Ouagadougou est la capitale.',
                    'points' => 1,
                ],
            ],
        ]);
    }

    public function test_student_does_not_see_correct_answer_via_show(): void
    {
        Sanctum::actingAs($this->student);

        $question = $this->getJson("/api/knowledge-checks/{$this->quiz->id}")
            ->assertStatus(200)
            ->json('data.questions.0');

        $this->assertIsArray($question);
        $this->assertArrayNotHasKey('correct_answer', $question);
        $this->assertArrayNotHasKey('explanation', $question);
        // Le reste de la question reste exposé (énoncé + options).
        $this->assertSame('Capitale du Burkina ?', $question['question']);
        $this->assertSame(['Bobo', 'Ouaga'], $question['options']);
    }

    public function test_student_does_not_see_correct_answer_via_get_by_chapter(): void
    {
        Sanctum::actingAs($this->student);

        $question = $this->getJson("/api/knowledge-checks/chapter/{$this->chapter->id}")
            ->assertStatus(200)
            ->json('data.questions.0');

        $this->assertArrayNotHasKey('correct_answer', $question);
        $this->assertArrayNotHasKey('explanation', $question);
    }

    public function test_student_does_not_see_correct_answer_via_index(): void
    {
        Sanctum::actingAs($this->student);

        $question = $this->getJson("/api/knowledge-checks?chapter_id={$this->chapter->id}")
            ->assertStatus(200)
            ->json('data.0.questions.0');

        $this->assertArrayNotHasKey('correct_answer', $question);
        $this->assertArrayNotHasKey('explanation', $question);
    }

    public function test_staff_owner_still_sees_correct_answer_via_show(): void
    {
        Sanctum::actingAs($this->owner);

        $question = $this->getJson("/api/knowledge-checks/{$this->quiz->id}")
            ->assertStatus(200)
            ->json('data.questions.0');

        $this->assertSame('Ouaga', $question['correct_answer']);
        $this->assertSame('Ouagadougou est la capitale.', $question['explanation']);
    }
}
