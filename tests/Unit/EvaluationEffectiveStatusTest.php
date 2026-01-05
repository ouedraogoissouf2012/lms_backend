<?php

namespace Tests\Unit;

use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationEffectiveStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test que le statut "terminee" est conservé
     */
    public function test_terminee_status_is_preserved()
    {
        $evaluation = Evaluation::factory()->create([
            'status' => 'terminee',
            'date_evaluation' => now()->subDays(1),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('terminee', $evaluation->getEffectiveStatus());
        $this->assertTrue($evaluation->isTerminee());
    }

    /**
     * Test qu'une évaluation passée (date + durée) devient "terminee"
     */
    public function test_past_evaluation_is_terminee()
    {
        // Créer une évaluation qui s'est terminée il y a 1 heure
        $evaluation = Evaluation::factory()->create([
            'status' => 'en_cours',
            'date_evaluation' => now()->subHours(2),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('terminee', $evaluation->getEffectiveStatus());
        $this->assertTrue($evaluation->isTerminee());
    }

    /**
     * Test qu'une évaluation en cours reste "en_cours"
     */
    public function test_ongoing_evaluation_stays_en_cours()
    {
        // Créer une évaluation qui a démarré il y a 30 min et dure 60 min
        $evaluation = Evaluation::factory()->create([
            'status' => 'en_cours',
            'date_evaluation' => now()->subMinutes(30),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('en_cours', $evaluation->getEffectiveStatus());
        $this->assertFalse($evaluation->isTerminee());
    }

    /**
     * Test qu'une évaluation planifiée reste "planifiee"
     */
    public function test_planned_evaluation_stays_planifiee()
    {
        // Créer une évaluation prévue demain
        $evaluation = Evaluation::factory()->create([
            'status' => 'planifiee',
            'date_evaluation' => now()->addDay(),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('planifiee', $evaluation->getEffectiveStatus());
        $this->assertFalse($evaluation->isTerminee());
    }

    /**
     * Test qu'une évaluation brouillon reste "brouillon"
     */
    public function test_draft_evaluation_stays_brouillon()
    {
        $evaluation = Evaluation::factory()->create([
            'status' => 'brouillon',
            'date_evaluation' => now()->addWeek(),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('brouillon', $evaluation->getEffectiveStatus());
        $this->assertFalse($evaluation->isTerminee());
    }

    /**
     * Test cas limite : évaluation qui se termine exactement maintenant
     */
    public function test_evaluation_ending_now_is_terminee()
    {
        // Créer une évaluation qui se termine dans 1 seconde (sera considérée terminée)
        $evaluation = Evaluation::factory()->create([
            'status' => 'en_cours',
            'date_evaluation' => now()->subMinutes(60)->addSecond(),
            'duree_minutes' => 60,
        ]);

        // Attendre 1 seconde pour s'assurer que l'évaluation est terminée
        sleep(1);

        $this->assertEquals('terminee', $evaluation->getEffectiveStatus());
        $this->assertTrue($evaluation->isTerminee());
    }

    /**
     * Test avec une durée de 0 minute
     */
    public function test_zero_duration_evaluation()
    {
        // Une évaluation avec durée 0 qui a déjà commencé est immédiatement terminée
        $evaluation = Evaluation::factory()->create([
            'status' => 'en_cours',
            'date_evaluation' => now()->subMinutes(1),
            'duree_minutes' => 0,
        ]);

        $this->assertEquals('terminee', $evaluation->getEffectiveStatus());
        $this->assertTrue($evaluation->isTerminee());
    }

    /**
     * Test avec une très longue durée (plusieurs jours)
     */
    public function test_long_duration_evaluation()
    {
        // Évaluation qui a commencé hier et dure 7 jours (ex: projet)
        $evaluation = Evaluation::factory()->create([
            'status' => 'en_cours',
            'date_evaluation' => now()->subDay(),
            'duree_minutes' => 7 * 24 * 60, // 7 jours en minutes
        ]);

        $this->assertEquals('en_cours', $evaluation->getEffectiveStatus());
        $this->assertFalse($evaluation->isTerminee());
    }

    /**
     * Test qu'une évaluation passée qui n'a pas été démarrée devient "terminee"
     */
    public function test_past_planned_evaluation_becomes_terminee()
    {
        // Évaluation planifiée il y a 2 heures, durée 60 min
        // Elle aurait dû se terminer il y a 1 heure
        $evaluation = Evaluation::factory()->create([
            'status' => 'planifiee',
            'date_evaluation' => now()->subHours(2),
            'duree_minutes' => 60,
        ]);

        $this->assertEquals('terminee', $evaluation->getEffectiveStatus());
        $this->assertTrue($evaluation->isTerminee());
    }
}
