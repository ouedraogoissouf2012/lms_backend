<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #503 — `TeacherEvaluationResultsService::buildResultats` doit émettre un
 * nombre de requêtes DB CONSTANT quel que soit l'effectif de la classe.
 *
 * Pattern « baseline vs afterGrowth » robuste : DEUX évaluations (classes 55 et
 * 56) mesurées dans le même test, avec UN seul mock KLASSCI qui renvoie un
 * roster différent selon le `classe_id` (match par argument). Les requêtes
 * constantes (eager-load, enrichissement, stats) s'annulent entre les deux
 * mesures ; seule une croissance par-étudiant ferait diverger le compte.
 *
 * @see app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php
 */
final class EvaluationResultsNoNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private Evaluation $evalSmall;
    private Evaluation $evalLarge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test-token',
        ]);

        $this->evalSmall = $this->makeEvaluation(klassciClasseId: 55);
        $this->evalLarge = $this->makeEvaluation(klassciClasseId: 56);

        // Un seul mock : roster de 2 pour la classe 55, de 5 pour la classe 56.
        $roster55 = $this->seedRoster(size: 2, offset: 0, evaluation: $this->evalSmall);
        $roster56 = $this->seedRoster(size: 5, offset: 100, evaluation: $this->evalLarge);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($roster55, $roster56): void {
            $mock->shouldReceive('getClasseEtudiants')->with('test-token', 55)->andReturn(['data' => $roster55]);
            $mock->shouldReceive('getClasseEtudiants')->with('test-token', 56)->andReturn(['data' => $roster56]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => []]);
            $mock->shouldReceive('getMatieres')->andReturn(['data' => []]);
        });
    }

    public function test_query_count_is_constant_as_class_size_grows(): void
    {
        Sanctum::actingAs($this->teacher);

        $baseline = $this->countDbQueries($this->evalSmall);       // 2 élèves
        $afterGrowth = $this->countDbQueries($this->evalLarge);    // 5 élèves

        self::assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté : {$baseline} requêtes users/submissions à 2 élèves vs {$afterGrowth} à 5."
        );
    }

    private function countDbQueries(Evaluation $evaluation): int
    {
        DB::enableQueryLog();
        $this->getJson("/api/evaluations/{$evaluation->id}/results-by-class")
            ->assertStatus(200);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries->filter(fn (string $q): bool =>
            str_contains($q, 'evaluation_submissions')
            || str_contains($q, '"users"') || str_contains($q, '`users`')
        )->count();
    }

    private function makeEvaluation(int $klassciClasseId): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_classe_id' => $klassciClasseId,
            'is_published' => true,
        ]);
    }

    /**
     * Crée $size users + une submission "live" chacun, retourne le roster KLASSCI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function seedRoster(int $size, int $offset, Evaluation $evaluation): array
    {
        $roster = [];
        foreach (range(1, $size) as $i) {
            $klassciId = 2000 + $offset + $i;
            $email = 'eleve' . ($offset + $i) . '@ecole.test';

            User::factory()->student()->for($this->institution)->create([
                'klassci_id' => $klassciId,
                'email' => $email,
            ]);
            EvaluationSubmission::create([
                'evaluation_id' => $evaluation->id,
                'klassci_etudiant_id' => $klassciId,
                'attempt' => 1,
                'status' => 'soumis',
                'started_at' => now()->subHour(),
                'submitted_at' => now(),
                'institution_id' => $this->institution->id,
            ]);

            $roster[] = ['id' => $klassciId, 'email' => $email, 'nom' => "Nom{$i}", 'prenom' => "Prenom{$i}"];
        }

        return $roster;
    }
}
