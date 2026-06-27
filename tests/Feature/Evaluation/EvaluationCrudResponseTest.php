<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `EvaluationCrudController`
 * (axe #1 — issue #300, test-first AVANT migration vers `RespondsWithJson`).
 *
 * Ce controller est MIXTE : il émet 4 réponses-enveloppe construites en dur
 * (`['success' => …]`) ET 4 réponses qui forwardent verbatim un payload déjà
 * construit par les services (`$result['payload']`, statut dynamique). Seules
 * les 4 PREMIÈRES sont migrées vers `successResponse`/`errorResponse`. Ce test
 * verrouille leur forme JSON EXACTE pour garantir qu'aucune ne change côté
 * client après migration :
 *
 *  - `index`        → succès `{success, data}` (message omis)
 *  - `show` (404)   → erreur `{success, message}`
 *  - `show` (200)   → succès `{success, data}` (message omis)
 *  - `store` (403)  → erreur `{success, message}` (enseignant KLASSCI manquant)
 *
 * Les 4 réponses `response()->json($result['payload'], …)` (store 201/409/500,
 * update, destroy, publish) NE sont PAS testées ici : leur enveloppe est bâtie
 * par les services (hors périmètre de cette migration controller-only). On ne
 * les touche pas, donc on ne les caractérise pas.
 *
 * Les erreurs sont assertées en `assertExactJson` (forme verrouillée au byte) ;
 * les succès via présence/absence de clés d'enveloppe (le contenu enrichi de
 * `data` dépend de KLASSCI et n'est pas l'objet du test).
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationCrudController.php
 */
final class EvaluationCrudResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();

        // Enseignant synchronisé KLASSCI — passe le role middleware et le
        // garde-fou `klassci_enseignant_id` du controller.
        $this->teacher = User::factory()->create([
            'institution_id'        => $this->institution->id,
            'role'                  => 'enseignant',
            'klassci_role'          => 'enseignant',
            'klassci_enseignant_id' => 42,
        ]);
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_returns_success_envelope_without_message(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/evaluations');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── show ─────────────────────────

    public function test_show_not_found_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/evaluations/999999');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Évaluation non trouvée']);
    }

    public function test_show_existing_returns_success_envelope_without_message(): void
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
        ]);

        Sanctum::actingAs($this->teacher);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── store ─────────────────────────

    public function test_store_without_klassci_enseignant_id_returns_403_error_envelope(): void
    {
        // Admin (passe le role middleware + la FormRequest::authorize) mais sans
        // identité enseignant KLASSCI → 403 émis par le controller (issue #124).
        $admin = User::factory()->create([
            'institution_id'        => $this->institution->id,
            'role'                  => 'admin',
            'klassci_role'          => 'admin',
            'klassci_enseignant_id' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 10,
            'klassci_classe_id'  => 20,
            'titre'              => 'Examen de caractérisation',
            'type'               => 'qcm',
            'duree_minutes'      => 60,
        ]);

        $response->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation.',
            ]);
    }
}
