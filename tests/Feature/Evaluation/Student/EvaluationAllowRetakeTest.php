<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #501 — `allow_retake` et `is_online` sont enfin lus au démarrage.
 */
final class EvaluationAllowRetakeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 5010,
            'klassci_token' => 'token-501',
        ]);
    }

    public function test_retake_is_refused_when_allow_retake_is_false(): void
    {
        $evaluation = $this->evaluation(['allow_retake' => false, 'max_attempts' => 3]);
        $this->submitted($evaluation);
        $this->openWindow($evaluation);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Les reprises ne sont pas autorisées pour cette évaluation.');
    }

    public function test_allow_retake_true_still_respects_max_attempts(): void
    {
        $evaluation = $this->evaluation(['allow_retake' => true, 'max_attempts' => 1]);
        $this->submitted($evaluation);
        $this->openWindow($evaluation);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Nombre maximum de tentatives atteint (1)');
    }

    public function test_offline_evaluation_cannot_start_on_lms(): void
    {
        $evaluation = $this->evaluation(['is_online' => false, 'allow_retake' => true]);
        $this->openWindow($evaluation);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Cette évaluation ne se passe pas en ligne.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function evaluation(array $overrides): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create(array_merge([
            'institution_id' => $this->institution->id,
            'klassci_evaluation_id' => 5011,
            'is_published' => true,
            'is_online' => true,
        ], $overrides));
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    private function submitted(Evaluation $evaluation): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'klassci_etudiant_id' => $this->student->klassci_id,
            'status' => 'soumis',
        ]);
    }

    private function openWindow(Evaluation $evaluation): void
    {
        $this->mock(KlassciProxyService::class, function ($mock) use ($evaluation): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn([
                'data' => [['id' => $evaluation->klassci_evaluation_id]],
            ]);
        });
    }
}
