<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereEvaluationsFetcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #269 — régression : 500 « Call to undefined method
 * App\Models\Evaluation::isLocked() » sur GET /api/lms/matieres/{id} quand la
 * matière possède une version en ligne (KLASSCI ↔ LMS).
 *
 * Cause : la branche « version en ligne » de `enrichKlassciEvaluation` appelait
 * `$quizLMS->isLocked()` / `->canBeEdited()` — méthodes inexistantes sur le
 * modèle. Le verrou est porté par {@see \App\Services\Evaluation\EvaluationStateService},
 * déjà injecté et utilisé correctement pour les évaluations LMS pures.
 *
 * Ces tests exercent le fetcher en isolation (KLASSCI mocké) et auraient
 * échoué avec une `Error` avant le correctif.
 *
 * @see app/Services/Matiere/MatiereEvaluationsFetcher.php
 * @see app/Services/Evaluation/EvaluationStateService.php
 */
#[CoversClass(MatiereEvaluationsFetcher::class)]
final class MatiereEvaluationsFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const MATIERE_ID = 7;

    private Institution $institution;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);

        // Résout le tenant comme en production (ResolveInstitution middleware) :
        // sans ça la global scope BelongsToInstitution no-op + log un warning.
        app(TenantManager::class)->set($this->institution);

        $this->user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => 'enseignant',
            'klassci_id'     => 1234,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Construit le fetcher avec un KlassciProxyService mocké renvoyant
     * `$klassciEvaluations` pour l'endpoint `evaluations`.
     *
     * @param  array<int, array<string, mixed>>  $klassciEvaluations
     */
    private function fetcherReturning(array $klassciEvaluations): MatiereEvaluationsFetcher
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($klassciEvaluations): void {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('fake-token', 'evaluations', 'GET')
                ->andReturn(['data' => $klassciEvaluations]);
        });

        return app(MatiereEvaluationsFetcher::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function klassciEvaluation(int $id, array $overrides = []): array
    {
        return array_merge([
            'id'      => $id,
            'titre'   => 'Devoir KLASSCI',
            'matiere' => ['id' => self::MATIERE_ID, 'nom' => 'Mathématiques'],
        ], $overrides);
    }

    public function test_online_version_unlocked_when_no_submissions(): void
    {
        $quiz = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 100,
            'klassci_matiere_id'    => self::MATIERE_ID,
            'is_locked'             => false,
        ]);

        $fetcher = $this->fetcherReturning([$this->klassciEvaluation(100)]);

        $result = $fetcher->fetchEvaluationsForMatiere('fake-token', self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 100);
        self::assertSame($quiz->id, $online['id']);
        self::assertFalse($online['is_locked'], 'Aucune soumission + flag false → non verrouillée.');
        self::assertTrue($online['can_be_edited'], 'Non verrouillée → éditable.');
    }

    public function test_online_version_locked_when_submissions_exist(): void
    {
        $quiz = Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 200,
            'klassci_matiere_id'    => self::MATIERE_ID,
            'is_locked'             => false,
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id'  => $quiz->id,
            'institution_id' => $this->institution->id,
        ]);

        $fetcher = $this->fetcherReturning([$this->klassciEvaluation(200)]);

        $result = $fetcher->fetchEvaluationsForMatiere('fake-token', self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 200);
        self::assertTrue($online['is_locked'], 'Une soumission existe → verrouillée (même flag false).');
        self::assertFalse($online['can_be_edited'], 'Verrouillée → non éditable.');
    }

    public function test_online_version_locked_when_flag_set(): void
    {
        $quiz = Evaluation::factory()->locked()->create([
            'institution_id'        => $this->institution->id,
            'klassci_evaluation_id' => 300,
            'klassci_matiere_id'    => self::MATIERE_ID,
        ]);

        $fetcher = $this->fetcherReturning([$this->klassciEvaluation(300)]);

        $result = $fetcher->fetchEvaluationsForMatiere('fake-token', self::MATIERE_ID, $this->user);

        $online = $this->onlineVersionFor($result, 300);
        self::assertTrue($online['is_locked'], 'Flag is_locked → verrouillée sans soumission.');
        self::assertFalse($online['can_be_edited']);
        self::assertSame($quiz->id, $online['id']);
    }

    /**
     * Récupère le bloc `online_version` de l'évaluation KLASSCI d'`id` donné.
     *
     * @param  array{evaluations_enrichies: array<int, array<string, mixed>>, evaluations_raw_count: int}  $result
     * @return array<string, mixed>
     */
    private function onlineVersionFor(array $result, int $klassciId): array
    {
        foreach ($result['evaluations_enrichies'] as $eval) {
            if (($eval['id'] ?? null) === $klassciId) {
                self::assertTrue($eval['has_online'], 'La version en ligne doit être détectée.');
                self::assertIsArray($eval['online_version']);

                return $eval['online_version'];
            }
        }

        self::fail("Évaluation KLASSCI {$klassciId} absente du résultat enrichi.");
    }
}
