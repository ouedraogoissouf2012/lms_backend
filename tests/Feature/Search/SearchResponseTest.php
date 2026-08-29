<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `SearchController`
 * (axe #1 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Une seule réponse est migrable : `saveSearchHistory`
 * (`{success:true, message:'Recherche sauvegardée'}`). Les 3 autres
 * (`globalSearch`, `suggestions`, `searchHistory`) exposent des clés RACINE
 * hors enveloppe (`query`/`results`/`total`/`categories`, `suggestions`,
 * `history`) que `successResponse()` ne reproduit pas ; elles restent inline.
 *
 * Toutes sont figées ici (l'étudiant ne déclenche AUCUN appel KLASSCI :
 * `searchUsers/classes/matieres` court-circuitent pour ce rôle).
 *
 * @see app/Http/Controllers/API/SearchController.php
 */
final class SearchResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create();
        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
    }

    // ───────────── saveSearchHistory (migrable) ─────────────

    public function test_save_search_history_returns_success_with_message_only(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/search/history', ['query' => 'mathématiques']);

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Recherche sauvegardée']);
    }

    // ───────────── réponses à clés RACINE (non migrables) ─────────────

    public function test_global_search_keeps_root_keys(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search?query=ab');

        $response->assertStatus(200);
        $body = $response->json();
        // #505 : `sources_failed` est une extension ASSUMÉE de ce contrat — clé
        // TOUJOURS présente (vide quand aucune source n'est tombée), afin qu'un
        // bucket vide cesse d'être ambigu. Extension additive : les cinq clés
        // historiques et leurs valeurs restent inchangées.
        $this->assertSame(
            ['success', 'query', 'results', 'total', 'categories', 'sources_failed'],
            array_keys($body),
        );
        $this->assertSame([], $body['sources_failed']);
        $this->assertTrue($body['success']);
        $this->assertSame('ab', $body['query']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(
            ['users', 'lessons', 'evaluations', 'classes', 'matieres'],
            array_keys($body['results']),
        );
    }

    public function test_suggestions_keeps_root_suggestions_key(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search/suggestions?query=ab');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'suggestions'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['suggestions']);
    }

    public function test_search_history_keeps_root_history_key(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/search/history', ['query' => 'physique']);

        $response = $this->getJson('/api/search/history');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'history'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertCount(1, $body['history']);
        $this->assertSame('physique', $body['history'][0]['query']);
    }
}
