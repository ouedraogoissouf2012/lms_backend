<?php

declare(strict_types=1);

namespace Tests\Feature\TeacherStats;

use App\Http\Middleware\EnsureRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `TeacherStatsController`
 * (axe #1 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Fige la forme EXACTE des 2 réponses migrables :
 *   - succès `{success:true, data:{matieres, classes, evaluations, lessons}}` ;
 *   - 403 `{success:false, message:'Accès réservé aux enseignants'}`.
 *
 * Le 500 (catch) n'est PAS couvert : il expose une clé racine `error` que
 * `errorResponse()` ne reproduit pas (clé `errors` plurielle, tableau), il
 * reste donc inline après migration — aucun risque de régression.
 *
 * @see app/Http/Controllers/API/TeacherStatsController.php
 */
final class TeacherStatsResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    public function test_get_stats_as_teacher_returns_success_with_counts(): void
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/teacher/stats');

        // Enseignant sans contenu : tous les compteurs à 0 — fige l'enveloppe
        // exacte `{success, data:{...}}` indépendamment des données.
        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'matieres' => 0,
                    'classes' => 0,
                    'evaluations' => 0,
                    'lessons' => 0,
                ],
            ]);
    }

    public function test_get_stats_for_non_teacher_returns_403_error_envelope(): void
    {
        // Le garde de rôle du controller est une défense en profondeur : en
        // production la route porte déjà `role:enseignant,coordinateur`. On
        // contourne ce middleware de route pour exercer la branche 403 PROPRE
        // au controller et figer sa forme JSON.
        $this->withoutMiddleware(EnsureRole::class);

        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/teacher/stats');

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Accès réservé aux enseignants']);
    }
}
