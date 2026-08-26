<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `EvaluationKlassciSyncController`
 * (axe #1 — issue #301, test-first AVANT migration vers `RespondsWithJson`).
 *
 * Le controller émet 7 réponses-enveloppe. Seules celles dont la forme correspond
 * EXACTEMENT au contrat canonique du trait (`{success, message?, data?}` /
 * `{success, message, errors?}`) sont migrables sans changer le JSON client.
 *
 * MIGRÉES (et donc verrouillées ici) — `syncToKlassci` :
 *  - 404 `{success, message}`                  (évaluation introuvable)
 *  - 400 `{success, message}`                  (aucune évaluation KLASSCI liée)
 *  - 200 `{success, message, data}`            (notes synchronisées)
 *
 * NON migrées (clés racine hors contrat que le trait n'émet pas → migrer
 * changerait le JSON), donc laissées inline et non caractérisées ici :
 *  - les deux 500 portent une clé `error` (singulier) en plus de `message` ;
 *  - les deux succès de `syncNotesToKlassci` portent `synced_count` (+ `errors`)
 *    à la racine.
 *
 * Erreurs en `assertExactJson` ; succès via présence/absence de clés d'enveloppe.
 *
 * @see app/Http/Controllers/API/Evaluation/EvaluationKlassciSyncController.php
 */
final class EvaluationKlassciSyncResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id'        => $this->institution->id,
            'role'                  => 'enseignant',
            'klassci_role'          => 'enseignant',
            'klassci_enseignant_id' => 42,
            'klassci_token' => 'token-sync-619',
        ]);
    }

    // ───────────────────────── syncToKlassci ─────────────────────────

    public function test_sync_to_klassci_not_found_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations/999999/sync-klassci');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Évaluation non trouvée']);
    }

    public function test_sync_to_klassci_without_linked_evaluation_returns_400_error_envelope(): void
    {
        // Pas d'évaluation KLASSCI liée → la branche d'envoi est sautée → 400.
        $evaluation = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => null,
        ]);

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/sync-klassci");

        $response->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Aucune évaluation KLASSCI liée']);
    }

    public function test_sync_to_klassci_success_returns_success_envelope_with_message_and_data(): void
    {
        // KLASSCI joignable : URL de base configurée + HTTP fakée pour que
        // `saveNotes()` réussisse et renvoie un payload déterministe.
        config(['services.klassci.url' => 'https://klassci.test']);
        Http::fake(['*' => Http::response(['success' => true, 'saved' => 1], 200)]);

        $evaluation = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 7777,
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id'      => $evaluation->id,
            'institution_id'     => $this->institution->id,
            'status'             => 'soumis',
            'feedback'           => null,
            'synced_to_klassci'  => false,
        ]);

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/sync-klassci");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Notes synchronisées vers KLASSCI', $body['message']);
        $this->assertArrayHasKey('data', $body);
    }

    /**
     * Fail-closed (#564) : une évaluation contenant une question à correction
     * manuelle (dissertation) ne peut pas produire de note finale automatique.
     * Sa synchronisation vers KLASSCI (SIS officiel) DOIT être bloquée tant
     * qu'aucune notation manuelle n'existe — jamais de note fausse/0 poussée.
     */
    public function test_sync_blocked_when_evaluation_has_manual_grading_question(): void
    {
        config(['services.klassci.url' => 'https://klassci.test']);
        Http::fake(['*' => Http::response(['success' => true, 'saved' => 1], 200)]);

        $evaluation = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 8888,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id'  => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type'           => 'dissertation',
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id'     => $evaluation->id,
            'institution_id'    => $this->institution->id,
            'status'            => 'soumis',
            'synced_to_klassci' => false,
        ]);

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/sync-klassci");

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
        // Aucune note poussée vers KLASSCI (fail-closed).
        Http::assertNothingSent();
        // La soumission ne doit PAS être marquée synchronisée.
        $this->assertDatabaseHas('evaluation_submissions', [
            'evaluation_id'     => $evaluation->id,
            'synced_to_klassci' => false,
        ]);
    }
}
