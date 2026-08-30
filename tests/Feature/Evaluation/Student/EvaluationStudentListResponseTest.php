<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `EvaluationStudentListController` (axe #1, groupe-04 — test-first AVANT
 * migration vers `RespondsWithJson`).
 *
 * Gèle la forme JSON exacte des 2 succès + 6 call-sites d'erreur. Les erreurs
 * migrables sont assertées en `assertExactJson` ; les succès via présence/absence
 * des clés d'enveloppe.
 *
 * UNE réponse N'EST PAS migrable et reste inline : le `catch` 500 de `myGrades`
 * expose une clé racine `error` (singulier) que le trait — qui ne produit que
 * `errors` (pluriel, tableau) — ne reproduit pas. Ce handler n'est pas figé par
 * un test : l'agrégateur `final` `StudentGradesAggregator` interdit un mock de
 * substitution et son code réel n'a aucun chemin déclenchant ce catch (le `catch`
 * interne de `getMatieres` avale déjà les erreurs KLASSCI). La consigne de migration
 * le laisse explicitement inline → aucune régression possible côté trait.
 *
 * NB : le libellé « Utilisateur sans ID KLASSCI synchronisé » (myEvaluations)
 * et « Utilisateur sans ID KlassCI synchronisé » (myGrades) diffèrent par leur
 * casse — incohérence existante PRÉSERVÉE telle quelle (axe #1 « DRY-only »).
 *
 * @see app/Http/Controllers/API/Evaluation/Student/EvaluationStudentListController.php
 */
final class EvaluationStudentListResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 5555,
            'klassci_token' => 'student-klassci-token',
        ]);
    }

    private function stripKlassciToken(): void
    {
        $this->student->klassci_token = null;
        $this->student->save();
    }

    private function stripKlassciId(): void
    {
        $this->student->forceFill(['klassci_id' => null])->save();
    }

    // ───────────────────────── myEvaluations ─────────────────────────

    public function test_my_evaluations_without_klassci_id_returns_401_error_envelope(): void
    {
        $this->stripKlassciId();
        Sanctum::actingAs($this->student->fresh());

        $response = $this->getJson('/api/evaluations/student');

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Utilisateur sans ID KLASSCI synchronisé',
            ]);
    }

    public function test_my_evaluations_without_klassci_token_returns_401_error_envelope(): void
    {
        $this->stripKlassciToken();
        Sanctum::actingAs($this->student->fresh());

        $response = $this->getJson('/api/evaluations/student');

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => MissingKlassciTokenException::CLIENT_MESSAGE,
            ]);
    }

    public function test_my_evaluations_without_resolved_class_returns_404_error_envelope(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            // Dashboard sans `classe.id` ⇒ classe non résolue.
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/evaluations/student');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Classe non trouvée']);
    }

    public function test_my_evaluations_success_returns_200_data_only(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint) {
                    return $endpoint === 'me/dashboard'
                        ? ['data' => ['classe' => ['id' => 777]]]
                        : ['data' => []];
                });
        });
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/evaluations/student');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data']);
        $this->assertArrayNotHasKey('message', $response->json());
    }

    public function test_my_evaluations_unexpected_error_returns_500_error_envelope(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new Exception('boom'));
        });
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/evaluations/student');

        $response->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Erreur lors de la récupération des évaluations',
            ]);
    }

    // ───────────────────────── myGrades ─────────────────────────

    public function test_my_grades_without_klassci_id_returns_401_error_envelope(): void
    {
        $this->stripKlassciId();
        Sanctum::actingAs($this->student->fresh());

        $response = $this->getJson('/api/my-grades');

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Utilisateur sans ID KlassCI synchronisé',
            ]);
    }

    public function test_my_grades_success_returns_200_data_only(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['success' => true, 'data' => []]);
        });
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/my-grades');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['matieres', 'moyenne_generale', 'total_matieres', 'total_evaluations'],
            ]);
        $this->assertArrayNotHasKey('message', $response->json());
    }
}
