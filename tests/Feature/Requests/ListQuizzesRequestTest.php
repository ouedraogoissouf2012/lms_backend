<?php

declare(strict_types=1);

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Matiere;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour ListQuizzesRequest (GET /api/quizzes) — issue #548.
 *
 * QuizCrudController::index forwardait $request->all() brut à
 * QuizCrudService::list() — le passage à $request->validated() ne doit PAS
 * faire disparaître silencieusement les filtres existants (lesson_id,
 * matiere_id, classe_id, status, type, sort), d'où le test de non-régression
 * dédié ci-dessous.
 */
final class ListQuizzesRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();

        $this->teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);
    }

    public function test_per_page_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/quizzes?per_page=100');

        $response->assertStatus(200);
    }

    public function test_per_page_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/quizzes?per_page=101');

        $response->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_matiere_id_filter_still_narrows_results(): void
    {
        Sanctum::actingAs($this->teacher);

        $matiere = Matiere::factory()->create(['institution_id' => $this->teacher->institution_id]);
        $matching = Quiz::factory()->create([
            'institution_id' => $this->teacher->institution_id,
            'created_by' => $this->teacher->id,
            'matiere_id' => $matiere->id,
        ]);
        Quiz::factory()->create([
            'institution_id' => $this->teacher->institution_id,
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->getJson("/api/quizzes?matiere_id={$matiere->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($matching->id));
        $this->assertCount(1, $ids);
    }
}
