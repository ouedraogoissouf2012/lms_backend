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
 * {@see \App\Http\Controllers\API\Dashboard\DashboardAdminController::stats}
 * (axe #1 — issue #294 : test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON EXACTE de l'unique réponse (1 succès `{success, data}`)
 * afin qu'une migration `response()->json([...])` → `successResponse($data)` ne
 * change RIEN côté client : statut 200, clés racine `['success', 'data']` (aucun
 * `message`, aucune clé hors enveloppe), et arborescence complète du payload
 * `data` figée clé par clé.
 *
 * Complémentaire de {@see DashboardAdminStatsIsolationTest} qui couvre déjà
 * l'isolation multi-tenant des counts : ce test-ci se concentre sur l'ENVELOPPE,
 * la seule chose que la migration touche.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardAdminController.php
 */
final class DashboardAdminResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_stats_returns_success_data_envelope_without_message(): void
    {
        $institution = Institution::factory()->create();
        $coordinator = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'coordinateur',
        ]);
        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/dashboard/stats');

        $response->assertStatus(200);

        $body = $response->json();
        // Enveloppe : exactement {success, data} — pas de message, pas de clé racine.
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);

        // Arborescence du payload figée (la migration ne doit déplacer aucune clé).
        $this->assertSame(
            ['users', 'lessons', 'quizzes', 'forum', 'notifications', 'recent_activity'],
            array_keys($body['data'])
        );
        $this->assertSame(['total', 'students', 'teachers'], array_keys($body['data']['users']));
        $this->assertSame(['total', 'published'], array_keys($body['data']['lessons']));
        $this->assertSame(['total', 'total_attempts'], array_keys($body['data']['quizzes']));
        $this->assertSame(['total_topics', 'total_posts'], array_keys($body['data']['forum']));
        $this->assertSame(['total', 'unread'], array_keys($body['data']['notifications']));
        $this->assertSame(
            ['new_users', 'new_lessons', 'new_quiz_attempts', 'new_forum_topics'],
            array_keys($body['data']['recent_activity'])
        );
    }
}
