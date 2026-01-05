<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluationCoordinatorRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test qu'un coordinateur ne peut pas créer une évaluation
     */
    public function test_coordinateur_cannot_create_evaluation()
    {
        // Créer un utilisateur coordinateur
        $coordinateur = User::factory()->create([
            'role' => 'coordinateur',
            'klassci_id' => 999,
        ]);

        // Authentifier avec Sanctum
        Sanctum::actingAs($coordinateur);

        // Tenter de créer une évaluation
        $response = $this->postJson('/api/evaluations', [
            'klassci_evaluation_id' => 1,
            'klassci_matiere_id' => 1,
            'matiere_nom' => 'Mathématiques',
            'klassci_classe_id' => 1,
            'classe_nom' => 'Terminale A',
            'klassci_enseignant_id' => 1,
            'enseignant_nom' => 'M. Dupont',
            'titre' => 'Test Évaluation',
            'type' => 'devoir',
            'status' => 'brouillon',
            'date_evaluation' => now()->addDays(1),
            'duree_minutes' => 60,
            'coefficient' => 2,
            'bareme' => 20,
        ]);

        // Vérifier que l'accès est refusé
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Accès refusé. Les coordinateurs ne peuvent pas créer d\'évaluations.'
        ]);

        // Vérifier qu'aucune évaluation n'a été créée
        $this->assertEquals(0, Evaluation::count());
    }

    /**
     * Test qu'un enseignant peut créer une évaluation
     */
    public function test_enseignant_can_create_evaluation()
    {
        // Créer un utilisateur enseignant
        $enseignant = User::factory()->create([
            'role' => 'enseignant',
            'klassci_id' => 1,
        ]);

        // Authentifier avec Sanctum
        Sanctum::actingAs($enseignant);

        // Créer une évaluation
        $response = $this->postJson('/api/evaluations', [
            'klassci_evaluation_id' => 1,
            'klassci_matiere_id' => 1,
            'matiere_nom' => 'Mathématiques',
            'klassci_classe_id' => 1,
            'classe_nom' => 'Terminale A',
            'klassci_enseignant_id' => 1,
            'enseignant_nom' => 'M. Dupont',
            'titre' => 'Test Évaluation',
            'type' => 'devoir',
            'status' => 'brouillon',
            'date_evaluation' => now()->addDays(1),
            'duree_minutes' => 60,
            'coefficient' => 2,
            'bareme' => 20,
        ]);

        // Vérifier le succès
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);

        // Vérifier qu'une évaluation a été créée
        $this->assertEquals(1, Evaluation::count());
    }

    /**
     * Test qu'un coordinateur ne peut pas mettre à jour une évaluation
     */
    public function test_coordinateur_cannot_update_evaluation()
    {
        // Créer une évaluation
        $evaluation = Evaluation::factory()->create([
            'titre' => 'Titre Original',
            'status' => 'brouillon',
        ]);

        // Créer un utilisateur coordinateur
        $coordinateur = User::factory()->create([
            'role' => 'coordinateur',
            'klassci_id' => 999,
        ]);

        // Authentifier avec Sanctum
        Sanctum::actingAs($coordinateur);

        // Tenter de mettre à jour
        $response = $this->putJson("/api/evaluations/{$evaluation->id}", [
            'titre' => 'Titre Modifié',
        ]);

        // Vérifier que l'accès est refusé
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Accès refusé. Les coordinateurs ne peuvent pas modifier des évaluations.'
        ]);

        // Vérifier que l'évaluation n'a pas été modifiée
        $evaluation->refresh();
        $this->assertEquals('Titre Original', $evaluation->titre);
    }

    /**
     * Test qu'un coordinateur ne peut pas supprimer une évaluation
     */
    public function test_coordinateur_cannot_delete_evaluation()
    {
        // Créer une évaluation
        $evaluation = Evaluation::factory()->create();

        // Créer un utilisateur coordinateur
        $coordinateur = User::factory()->create([
            'role' => 'coordinateur',
            'klassci_id' => 999,
        ]);

        // Authentifier avec Sanctum
        Sanctum::actingAs($coordinateur);

        // Tenter de supprimer
        $response = $this->deleteJson("/api/evaluations/{$evaluation->id}");

        // Vérifier que l'accès est refusé
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Accès refusé. Les coordinateurs ne peuvent pas supprimer des évaluations.'
        ]);

        // Vérifier que l'évaluation existe toujours
        $this->assertDatabaseHas('evaluations', ['id' => $evaluation->id]);
    }

    /**
     * Test qu'un coordinateur peut consulter les évaluations (lecture seule)
     */
    public function test_coordinateur_can_read_evaluations()
    {
        // Créer des évaluations
        Evaluation::factory()->count(3)->create();

        // Créer un utilisateur coordinateur
        $coordinateur = User::factory()->create([
            'role' => 'coordinateur',
            'klassci_id' => 999,
        ]);

        // Authentifier avec Sanctum
        Sanctum::actingAs($coordinateur);

        // Consulter la liste
        $response = $this->getJson('/api/evaluations');

        // Vérifier le succès
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data',
        ]);
    }
}
