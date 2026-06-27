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
 * {@see \App\Http\Controllers\API\Dashboard\DashboardStudentController::student}
 * (axe #1 — issue #295 : endpoint NON testé → test-first OBLIGATOIRE avant
 * migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON EXACTE de l'unique réponse (1 succès `{success, data}`)
 * pour qu'une migration `response()->json([...])` → `successResponse($data)` soit
 * un NO-OP côté client : statut 200, clés racine `['success', 'data']` (aucun
 * `message`), et clés du payload `data` (déléguées au `StudentDashboardService`)
 * figées au niveau supérieur.
 *
 * On teste avec un étudiant SANS données rattachées : tous les blocs sont vides
 * mais présents — c'est exactement la surface que la migration doit préserver
 * (l'enveloppe), pas le contenu agrégé (couvert ailleurs par le service).
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardStudentController.php
 * @see app/Services/Dashboard/StudentDashboardService.php
 */
final class DashboardStudentResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_student_returns_success_data_envelope_without_message(): void
    {
        $institution = Institution::factory()->create();
        $student = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/dashboard/student');

        $response->assertStatus(200);

        $body = $response->json();
        // Enveloppe : exactement {success, data} — pas de message, pas de clé racine.
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);

        // Clés de premier niveau du payload (contrat du service) figées.
        $this->assertSame(
            [
                'ongoing_lessons',
                'completed_lessons',
                'upcoming_quizzes',
                'progression',
                'notifications',
                'forum_activity',
            ],
            array_keys($body['data'])
        );
        $this->assertSame(['recent', 'unread_count'], array_keys($body['data']['notifications']));
    }
}
