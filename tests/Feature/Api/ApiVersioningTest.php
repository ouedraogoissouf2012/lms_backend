<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests du versioning d'API par path (#217).
 *
 * Invariants :
 *   - le path NON-VERSIONNÉ `/api/...` reste fonctionnel (rétrocompat) ;
 *   - l'alias `/api/v1/...` sert exactement les mêmes routes ;
 *   - `/api/v2/...` existe structurellement mais ne sert encore aucune route
 *     v1 (espace réservé aux futurs breaking changes).
 *
 * @see bootstrap/app.php (montage then: v1/v2)
 * @see docs/API_VERSIONING.md
 */
final class ApiVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_unversioned_path_still_works(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson('/api/lessons')->assertStatus(200);
    }

    public function test_v1_alias_serves_same_routes(): void
    {
        Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->student);

        $unversioned = $this->getJson('/api/lessons');
        $versioned = $this->getJson('/api/v1/lessons');

        $unversioned->assertStatus(200);
        $versioned->assertStatus(200);

        // Même contrat : les deux paths renvoient la même structure de données.
        $this->assertSame(
            $unversioned->json('success'),
            $versioned->json('success'),
        );
    }

    public function test_v1_alias_enforces_same_auth(): void
    {
        // Sans token, l'alias v1 exige aussi l'authentification.
        $this->getJson('/api/v1/lessons')->assertStatus(401);
    }

    public function test_v2_namespace_exists_but_serves_no_v1_route_yet(): void
    {
        Sanctum::actingAs($this->student);

        // Aucune route v2 définie → un endpoint v1 n'existe pas sous v2 (404).
        $this->getJson('/api/v2/lessons')->assertStatus(404);
    }

    public function test_named_routes_are_not_duplicated(): void
    {
        // Le double montage ne doit pas casser la résolution de route nommée
        // utilisée en interne (ex. téléchargement de fichier).
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.files.download'),
            'La route nommée non-versionnée doit rester résolvable'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('v1.api.files.download'),
            'L\'alias v1 doit exposer la route nommée préfixée v1.'
        );
    }
}
