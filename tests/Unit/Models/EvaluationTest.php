<?php

namespace Tests\Unit\Models;

use App\Models\Evaluation;
use Tests\TestCase;

/**
 * Tests du statut effectif d'une évaluation (Evaluation::getEffectiveStatus()).
 *
 * Règles métier vérifiées :
 * - Status déjà 'terminee'        → reste 'terminee'
 * - date_evaluation NULL (brouillon non daté) → renvoie le status stocké, SANS exception
 * - échéance (date + durée) passée → 'terminee'
 * - échéance encore future         → status stocké inchangé
 *
 * ## Perspective 10 ans
 * getEffectiveStatus() est appelé sur CHAQUE évaluation du listing
 * (GET /api/evaluations). Une évaluation sans date (brouillon) faisait
 * `copy()` sur null → 500 sur tout le listing (issue #268). Ce test
 * verrouille le contrat : pas de date ⇒ pas de déduction ⇒ status stocké.
 *
 * Tests en mémoire : aucun accès DB (modèle instancié, attributs posés à la main).
 */
class EvaluationTest extends TestCase
{
    private function makeEvaluation(array $attributes): Evaluation
    {
        $evaluation = new Evaluation();

        foreach ($attributes as $key => $value) {
            $evaluation->{$key} = $value;
        }

        return $evaluation;
    }

    /**
     * ✅ EDGE CASE (issue #268) : date_evaluation NULL → renvoie le status stocké, aucune exception.
     */
    public function test_returns_stored_status_when_date_evaluation_is_null(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'brouillon',
            'date_evaluation' => null,
            'duree_minutes' => 60,
        ]);

        $this->assertSame('brouillon', $evaluation->getEffectiveStatus());
    }

    /**
     * ✅ EDGE CASE : date_evaluation NULL avec un autre status → status préservé tel quel.
     */
    public function test_returns_planifiee_status_when_date_evaluation_is_null(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'planifiee',
            'date_evaluation' => null,
            'duree_minutes' => null,
        ]);

        $this->assertSame('planifiee', $evaluation->getEffectiveStatus());
    }

    /**
     * ✅ HAPPY PATH : status déjà 'terminee' → reste 'terminee' (court-circuit).
     */
    public function test_keeps_terminee_when_already_terminee(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'terminee',
            'date_evaluation' => null,
            'duree_minutes' => 60,
        ]);

        $this->assertSame('terminee', $evaluation->getEffectiveStatus());
    }

    /**
     * ✅ HAPPY PATH : échéance (date + durée) passée → 'terminee'.
     */
    public function test_returns_terminee_when_end_date_is_in_the_past(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'en_cours',
            'date_evaluation' => now()->subHours(3),
            'duree_minutes' => 60,
        ]);

        $this->assertSame('terminee', $evaluation->getEffectiveStatus());
    }

    /**
     * ✅ HAPPY PATH : échéance encore future → status stocké inchangé.
     */
    public function test_returns_stored_status_when_evaluation_is_upcoming(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'planifiee',
            'date_evaluation' => now()->addDays(2),
            'duree_minutes' => 60,
        ]);

        $this->assertSame('planifiee', $evaluation->getEffectiveStatus());
    }

    /**
     * ✅ isTerminee() s'appuie sur getEffectiveStatus() : NULL ne doit pas exploser.
     */
    public function test_is_terminee_is_false_when_date_evaluation_is_null(): void
    {
        $evaluation = $this->makeEvaluation([
            'status' => 'brouillon',
            'date_evaluation' => null,
            'duree_minutes' => 60,
        ]);

        $this->assertFalse($evaluation->isTerminee());
    }
}
