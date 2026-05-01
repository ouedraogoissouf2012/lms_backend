<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use App\Models\Quiz;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Classe;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreQuizRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $coordinator;
    private User $student;
    private Lesson $lesson;
    private Matiere $matiere;
    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->coordinator = User::factory()->coordinator()->for($this->institution)->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->lesson = Lesson::factory()->for($this->institution)->create();
        $this->matiere = Matiere::factory()->for($this->institution)->create();
        $this->classe = Classe::factory()->for($this->institution)->create();
    }

    public function test_unauthenticated_cannot_store(): void
    {
        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
        ]);

        $response->assertStatus(401);
    }

    public function test_student_cannot_store(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
        ]);

        $response->assertStatus(403);
    }

    public function test_teacher_can_store(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'description' => 'A test quiz',
            'type' => 'formative',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.title', 'Test Quiz');
    }

    public function test_coordinator_can_store(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
    }

    public function test_title_is_required(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_title_max_length_enforced(): void
    {
        Sanctum::actingAs($this->teacher);

        $longTitle = str_repeat('a', 256);

        $response = $this->postJson('/api/quizzes', [
            'title' => $longTitle,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('title');
    }

    public function test_invalid_lesson_id_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'lesson_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lesson_id');
    }

    public function test_invalid_matiere_id_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'matiere_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('matiere_id');
    }

    public function test_invalid_classe_id_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'classe_id' => 99999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('classe_id');
    }

    public function test_invalid_type_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('type');
    }

    public function test_valid_type_values(): void
    {
        Sanctum::actingAs($this->teacher);

        $types = ['formative', 'summative', 'diagnostic'];

        foreach ($types as $type) {
            $response = $this->postJson('/api/quizzes', [
                'title' => "Test Quiz {$type}",
                'type' => $type,
            ]);

            $response->assertStatus(201);
        }
    }

    public function test_duration_minutes_must_be_integer(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'duration_minutes' => 'not_an_integer',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('duration_minutes');
    }

    public function test_duration_minutes_must_be_at_least_one(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'duration_minutes' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('duration_minutes');
    }

    public function test_max_attempts_must_be_integer(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'max_attempts' => 'not_an_integer',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('max_attempts');
    }

    public function test_passing_score_must_be_numeric(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'passing_score' => 'not_numeric',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('passing_score');
    }

    public function test_passing_score_range_enforced(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
            'passing_score' => 150,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('passing_score');
    }

    public function test_boolean_flags_enforced(): void
    {
        Sanctum::actingAs($this->teacher);

        $flags = ['shuffle_questions', 'shuffle_answers', 'show_correct_answers', 'allow_review'];

        foreach ($flags as $flag) {
            $response = $this->postJson('/api/quizzes', [
                'title' => 'Test Quiz',
                $flag => 'not_a_boolean',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors($flag);
        }
    }

    public function test_store_with_all_valid_fields(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Complete Quiz',
            'description' => 'A complete test quiz',
            'instructions' => 'Answer all questions carefully',
            'lesson_id' => $this->lesson->id,
            'matiere_id' => $this->matiere->id,
            'classe_id' => $this->classe->id,
            'type' => 'summative',
            'duration_minutes' => 60,
            'max_attempts' => 3,
            'passing_score' => 70,
            'shuffle_questions' => true,
            'shuffle_answers' => true,
            'show_correct_answers' => false,
            'allow_review' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.title', 'Complete Quiz');
        $response->assertJsonPath('data.duration_minutes', 60);
        $response->assertJsonPath('data.max_attempts', 3);
    }

    public function test_created_by_is_set_to_current_user(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', [
            'title' => 'Test Quiz',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.created_by', $this->teacher->id);
    }
}
