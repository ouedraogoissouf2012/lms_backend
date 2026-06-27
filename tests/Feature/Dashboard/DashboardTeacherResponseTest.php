<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * {@see \App\Http\Controllers\API\Dashboard\DashboardTeacherController::teacher}
 * (axe #1 — issue #296 : endpoint NON testé → test-first OBLIGATOIRE avant
 * migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON EXACTE de l'unique réponse (1 succès `{success, data}`)
 * pour qu'une migration `response()->json([...])` → `successResponse($data)` soit
 * un NO-OP côté client : statut 200, clés racine `['success', 'data']` (aucun
 * `message`), et clés de premier niveau du payload `data` figées.
 *
 * On teste avec un enseignant SANS cours/quiz/topics : tous les blocs sont à zéro
 * ou vides mais présents — la migration ne touche que l'enveloppe, pas l'agrégat.
 * La route exige `role:enseignant,coordinateur` → l'utilisateur est `enseignant`.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardTeacherController.php
 */
final class DashboardTeacherResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_teacher_returns_success_data_envelope_without_message(): void
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);
        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/dashboard/teacher');

        $response->assertStatus(200);

        $body = $response->json();
        // Enveloppe : exactement {success, data} — pas de message, pas de clé racine.
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);

        // Clés de premier niveau du payload figées (la migration ne déplace rien).
        $this->assertSame(
            ['lessons', 'students', 'quizzes', 'forum'],
            array_keys($body['data'])
        );
        $this->assertSame(
            ['total', 'published', 'draft', 'top_lessons'],
            array_keys($body['data']['lessons'])
        );
        $this->assertSame(['active_last_7_days'], array_keys($body['data']['students']));
        $this->assertSame(
            ['total', 'published', 'to_grade', 'to_grade_list', 'total_attempts', 'average_score'],
            array_keys($body['data']['quizzes'])
        );
        $this->assertSame(
            ['unresolved_topics', 'unresolved_topics_list'],
            array_keys($body['data']['forum'])
        );
    }
}
