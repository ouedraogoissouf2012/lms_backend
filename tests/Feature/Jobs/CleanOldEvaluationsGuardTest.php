<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationSubmissionStatus;
use App\Jobs\CleanOldEvaluations;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #705 — délai de grâce + copie en_cours protège l'évaluation.
 */
final class CleanOldEvaluationsGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_terminee_today_is_not_deleted_tonight(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $inst->id,
            'is_published' => true,
            'status' => EvaluationStatus::Terminee->value,
            'date_evaluation' => now(),
        ]);

        $this->app->call([new CleanOldEvaluations, 'handle']);

        $this->assertFalse($evaluation->fresh()->trashed());
    }

    public function test_en_cours_submission_protects_parent_evaluation(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $inst->id,
            'is_published' => true,
            'status' => EvaluationStatus::Planifiee->value,
            'date_evaluation' => now()->subDays(10),
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $inst->id,
            'status' => EvaluationSubmissionStatus::EnCours->value,
            'submitted_at' => null,
        ]);

        $this->app->call([new CleanOldEvaluations, 'handle']);

        $this->assertFalse($evaluation->fresh()->trashed());
    }

    public function test_old_eval_without_copy_is_soft_deleted(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $inst->id,
            'is_published' => true,
            'status' => EvaluationStatus::Planifiee->value,
            'date_evaluation' => now()->subDays(10),
        ]);

        $this->app->call([new CleanOldEvaluations, 'handle']);

        $this->assertSoftDeleted('evaluations', ['id' => $evaluation->id]);
    }
}
