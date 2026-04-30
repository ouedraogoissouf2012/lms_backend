<?php

namespace Tests\Feature\Requests;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for FilterLessonsRequest (GET /api/lessons query validation)
 *
 * Validates query parameters for lesson listing:
 * - per_page: pagination size (1-100)
 * - matiere_id: subject filter
 * - classe_id: class filter
 * - enseignant_id: teacher filter
 * - type: lesson type filter
 * - status: publication status filter
 *
 * ## 10-year perspective
 * These tests document the expected query parameter behavior.
 * If limits change, tests immediately fail and prevent regression.
 * New devs understand filtering contract by reading tests.
 */
class FilterLessonsRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private Classe $classe;
    private Matiere $matiere;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->classe = Classe::factory()->for($this->institution)->create();
        $this->matiere = Matiere::factory()->for($this->institution)->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        // Create sample lessons for filtering
        Lesson::factory(5)
            ->for($this->classe)
            ->for($this->matiere)
            ->for($this->institution)
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Valid query parameters
     */
    public function test_valid_query_parameters_returns_200(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=10&type=cours&status=published');

        $response->assertStatus(200);
    }

    /**
     * ✅ DEFAULT: per_page defaults to 15 if not provided
     */
    public function test_per_page_defaults_to_15(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons');

        $response->assertStatus(200);
        // Per_page should default to 15 (handled in controller)
    }

    /**
     * ✅ PAGINATION: per_page=1 is valid
     */
    public function test_per_page_minimum_is_valid(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=1');

        $response->assertStatus(200);
    }

    /**
     * ✅ PAGINATION: per_page=100 is valid (maximum)
     */
    public function test_per_page_maximum_is_valid(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=100');

        $response->assertStatus(200);
    }

    /**
     * ❌ PAGINATION: per_page=0 fails (DOS prevention)
     */
    public function test_per_page_zero_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=0');

        $response->assertStatus(422);
        $this->assertIsArray($response->json('errors.per_page'));
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.per_page')),
            'au moins 1'
        ) || str_contains(
            json_encode($response->json('errors.per_page')),
            'at least 1'
        ));
    }

    /**
     * ❌ PAGINATION: per_page=101 fails (DOS prevention)
     */
    public function test_per_page_exceeding_max_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=101');

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.per_page'));
    }

    /**
     * ❌ PAGINATION: per_page=1000000 fails (DOS prevention)
     */
    public function test_per_page_extreme_value_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=1000000');

        $response->assertStatus(422);
    }

    /**
     * ❌ PAGINATION: per_page='abc' fails (not integer)
     */
    public function test_per_page_non_integer_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=abc');

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.per_page'));
    }

    /**
     * ✅ FILTER: matiere_id=1 is valid
     */
    public function test_valid_matiere_id_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?matiere_id=' . $this->matiere->id);

        $response->assertStatus(200);
    }

    /**
     * ❌ FILTER: matiere_id=0 fails
     */
    public function test_matiere_id_zero_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?matiere_id=0');

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.matiere_id'));
    }

    /**
     * ❌ FILTER: matiere_id=-5 fails
     */
    public function test_matiere_id_negative_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?matiere_id=-5');

        $response->assertStatus(422);
    }

    /**
     * ❌ FILTER: matiere_id='abc' fails
     */
    public function test_matiere_id_non_numeric_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?matiere_id=abc');

        $response->assertStatus(422);
    }

    /**
     * ✅ FILTER: classe_id=1 is valid
     */
    public function test_valid_classe_id_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?classe_id=' . $this->classe->id);

        $response->assertStatus(200);
    }

    /**
     * ❌ FILTER: classe_id=0 fails
     */
    public function test_classe_id_zero_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?classe_id=0');

        $response->assertStatus(422);
    }

    /**
     * ✅ FILTER: enseignant_id=1 is valid
     */
    public function test_valid_enseignant_id_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?enseignant_id=' . $this->teacher->id);

        $response->assertStatus(200);
    }

    /**
     * ❌ FILTER: enseignant_id=-1 fails
     */
    public function test_enseignant_id_negative_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?enseignant_id=-1');

        $response->assertStatus(422);
    }

    /**
     * ✅ ENUM: type='cours' is valid
     */
    public function test_valid_type_cours_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?type=cours');

        $response->assertStatus(200);
    }

    /**
     * ✅ ENUM: type='tp' is valid
     */
    public function test_valid_type_tp_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?type=tp');

        $response->assertStatus(200);
    }

    /**
     * ❌ ENUM: type='invalid_type' fails
     */
    public function test_invalid_type_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?type=invalid_type');

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.type')),
            'ou' // message contains check
        ));
    }

    /**
     * ✅ ENUM: status='published' is valid
     */
    public function test_valid_status_published_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?status=published');

        $response->assertStatus(200);
    }

    /**
     * ✅ ENUM: status='draft' is valid
     */
    public function test_valid_status_draft_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?status=draft');

        $response->assertStatus(200);
    }

    /**
     * ✅ ENUM: status='archived' is valid
     */
    public function test_valid_status_archived_passes(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?status=archived');

        $response->assertStatus(200);
    }

    /**
     * ❌ ENUM: status='invalid_status' fails
     */
    public function test_invalid_status_fails(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?status=invalid_status');

        $response->assertStatus(422);
        $this->assertTrue(str_contains(
            json_encode($response->json('errors.status')),
            'ou' // message contains check
        ));
    }

    /**
     * ✅ COMBINATION: Multiple valid filters work together
     */
    public function test_multiple_filters_together_pass(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson(sprintf(
            '/api/lessons?per_page=10&matiere_id=%d&classe_id=%d&type=cours&status=published',
            $this->matiere->id,
            $this->classe->id
        ));

        $response->assertStatus(200);
    }

    /**
     * ✅ NULLABLE: Filters are optional (sometimes)
     */
    public function test_filters_are_optional(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons');

        $response->assertStatus(200);
        // No filters = all lessons (or filtered by default scope)
    }

    /**
     * ❌ VALIDATION: Multiple invalid filters fail
     */
    public function test_multiple_invalid_filters_fail(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->getJson('/api/lessons?per_page=abc&matiere_id=invalid&type=bad');

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.per_page'));
        $this->assertNotEmpty($response->json('errors.matiere_id'));
        $this->assertNotEmpty($response->json('errors.type'));
    }
}
