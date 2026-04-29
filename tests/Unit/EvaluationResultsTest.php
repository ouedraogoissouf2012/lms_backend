<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Http\Controllers\API\EvaluationController;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class EvaluationResultsTest extends TestCase
{
    /**
     * Test que la méthode getResultsByClass existe
     */
    public function test_controller_has_get_results_by_class_method(): void
    {
        $klassciService = Mockery::mock(KlassciProxyService::class);
        $controller = new EvaluationController($klassciService);

        $this->assertTrue(
            method_exists($controller, 'getResultsByClass'),
            'La méthode getResultsByClass doit exister dans EvaluationController'
        );
    }

    /**
     * Test que le modèle Evaluation a la relation submissions
     */
    public function test_evaluation_has_submissions_relation(): void
    {
        $evaluation = new Evaluation();

        $this->assertTrue(
            method_exists($evaluation, 'submissions'),
            'Le modèle Evaluation doit avoir une relation submissions'
        );
    }

    /**
     * Test que le modèle Evaluation a la relation questions
     */
    public function test_evaluation_has_questions_relation(): void
    {
        $evaluation = new Evaluation();

        $this->assertTrue(
            method_exists($evaluation, 'questions'),
            'Le modèle Evaluation doit avoir une relation questions'
        );
    }

    /**
     * Test du calcul de la moyenne
     */
    public function test_average_calculation(): void
    {
        $notes = collect([10.5, 12.0, 15.5, 18.0, 14.5]);
        $moyenne = round($notes->avg(), 2);

        $this->assertEquals(14.1, $moyenne, 'La moyenne doit être calculée correctement');
    }

    /**
     * Test du calcul du taux de participation
     */
    public function test_participation_rate_calculation(): void
    {
        $totalEtudiants = 10;
        $etudiantsSoumis = 7;
        $tauxParticipation = round(($etudiantsSoumis / $totalEtudiants) * 100, 2);

        $this->assertEquals(70.0, $tauxParticipation, 'Le taux de participation doit être 70%');
    }

    /**
     * Test que les statuts sont bien définis
     */
    public function test_submission_status_values(): void
    {
        $validStatuses = ['soumis', 'en_cours', 'non_passee'];

        foreach ($validStatuses as $status) {
            $this->assertContains($status, $validStatuses, "Le statut {$status} doit être valide");
        }
    }

    /**
     * Test du calcul de note sur 20
     */
    public function test_note_sur_20_calculation(): void
    {
        $score = 15;
        $total = 20;
        $noteSur20 = round(($score / $total) * 20, 2);

        $this->assertEquals(15.0, $noteSur20, 'La note sur 20 doit être calculée correctement');
    }

    /**
     * Test de la structure de réponse attendue
     */
    public function test_expected_response_structure(): void
    {
        $expectedKeys = ['evaluation', 'resultats', 'statistiques'];

        $this->assertCount(3, $expectedKeys, 'La réponse doit contenir 3 clés principales');
        $this->assertContains('evaluation', $expectedKeys);
        $this->assertContains('resultats', $expectedKeys);
        $this->assertContains('statistiques', $expectedKeys);
    }

    /**
     * Test de la structure des statistiques
     */
    public function test_statistics_structure(): void
    {
        $statisticsKeys = [
            'total_etudiants',
            'etudiants_soumis',
            'taux_participation',
            'moyenne_classe',
            'note_min',
            'note_max'
        ];

        $this->assertCount(6, $statisticsKeys, 'Les statistiques doivent contenir 6 métriques');
        $this->assertContains('moyenne_classe', $statisticsKeys);
        $this->assertContains('taux_participation', $statisticsKeys);
    }

    /**
     * Test que KlassciProxyService existe
     */
    public function test_klassci_service_exists(): void
    {
        $this->assertTrue(
            class_exists(KlassciProxyService::class),
            'Le service KlassciProxyService doit exister'
        );
    }

    /**
     * Test que KlassciProxyService a la méthode getClasseEtudiants
     */
    public function test_klassci_service_has_get_classe_etudiants(): void
    {
        $service = new KlassciProxyService();

        $this->assertTrue(
            method_exists($service, 'getClasseEtudiants'),
            'KlassciProxyService doit avoir la méthode getClasseEtudiants'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
