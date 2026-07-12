<?php

namespace Tests\Feature\Requests;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StoreLessonRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classe $classe;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->classe = Classe::factory()->for($this->institution)->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
    }

    public function test_unknown_matiere_id_is_rejected_on_create(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Cours sans matière locale',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'matiere_id' => 999999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('matiere_id')
            ->assertJsonPath('errors.matiere_id', ['La matière n\'existe pas']);
    }

    public function test_other_institution_matiere_id_is_rejected_on_create(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherMatiere = Matiere::factory()->for($otherInstitution)->create();
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Cours cross-tenant',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'matiere_id' => $otherMatiere->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('matiere_id');
    }

    public function test_xss_in_description_is_sanitized(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test Lesson',
            'description' => '<script>alert("XSS")</script>',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(201);

        $lesson = Lesson::latest()->first();
        $this->assertStringNotContainsString('<script>', (string) $lesson?->description);
    }

    public function test_sql_injection_attempt_keeps_lessons_table(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/lessons', [
            'title' => "'; DROP TABLE lessons; --",
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $this->assertTrue(Schema::hasTable('lessons'), 'lessons table was dropped');
    }

    public function test_duration_exceeding_max_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'duree_estimee_minutes' => 481,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.duree_estimee_minutes', ['La durée estimée ne doit pas dépasser 480 minutes']);
    }

    public function test_duration_negative_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'duree_estimee_minutes' => -5,
        ]);

        $response->assertStatus(422);
    }
}
