<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #503 — non-régression fonctionnelle de `getResultsByClass` après le
 * préchargement anti-N+1 : la bonne submission "live" reste rattachée à chaque
 * étudiant (via klassci_id local ou fallback ID KLASSCI direct), et les
 * soumissions `[PRACTICE]` restent exclues.
 *
 * @see app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php
 */
final class EvaluationResultsCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test-token',
        ]);
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_classe_id' => 55,
            'is_published' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $roster
     */
    private function mockRoster(array $roster): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($roster): void {
            $mock->shouldReceive('getClasseEtudiants')->andReturn(['data' => $roster]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => []]);
            $mock->shouldReceive('getMatieres')->andReturn(['data' => []]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resultFor(int $klassciId): ?array
    {
        $resultats = $this->getJson("/api/evaluations/{$this->evaluation->id}/results-by-class")
            ->assertStatus(200)
            ->json('data.resultats');

        foreach ($resultats as $r) {
            if ((int) $r['etudiant_id'] === $klassciId) {
                return $r;
            }
        }

        return null;
    }

    public function test_live_submission_is_attached_via_local_klassci_id(): void
    {
        User::factory()->student()->for($this->institution)->create([
            'klassci_id' => 700,
            'email' => 'sync@ecole.test',
        ]);
        EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => 700,
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'institution_id' => $this->institution->id,
        ]);
        $this->mockRoster([['id' => 700, 'email' => 'sync@ecole.test', 'nom' => 'A', 'prenom' => 'B']]);

        Sanctum::actingAs($this->teacher);
        $result = $this->resultFor(700);

        self::assertNotNull($result);
        self::assertSame('soumis', $result['status']);
    }

    public function test_fallback_by_direct_klassci_id_when_no_local_user(): void
    {
        // Aucun user local (email non synchronisé) → résolution par ID KLASSCI direct.
        EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => 800,
            'attempt' => 1,
            'status' => 'corrige',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'institution_id' => $this->institution->id,
        ]);
        $this->mockRoster([['id' => 800, 'email' => 'notsync@ecole.test', 'nom' => 'C', 'prenom' => 'D']]);

        Sanctum::actingAs($this->teacher);
        $result = $this->resultFor(800);

        self::assertNotNull($result);
        self::assertSame('corrige', $result['status']);
    }

    public function test_practice_submission_is_excluded(): void
    {
        User::factory()->student()->for($this->institution)->create([
            'klassci_id' => 900,
            'email' => 'practice@ecole.test',
        ]);
        EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => 900,
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'feedback' => '[PRACTICE] Entraînement - note non officielle',
            'institution_id' => $this->institution->id,
        ]);
        $this->mockRoster([['id' => 900, 'email' => 'practice@ecole.test', 'nom' => 'E', 'prenom' => 'F']]);

        Sanctum::actingAs($this->teacher);
        $result = $this->resultFor(900);

        self::assertNotNull($result);
        // La soumission [PRACTICE] est ignorée → statut "non passée".
        self::assertSame('non_passee', $result['status']);
    }
}
