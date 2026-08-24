<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #540 — l'index unique de la base ignore `institution_id` ; les requêtes
 * Eloquent, elles, portent le global scope `institution` de
 * {@see \App\Models\Traits\BelongsToInstitution}. Si les deux ne voient pas le
 * même jeu de lignes, le filet base se retourne contre l'application.
 *
 * ## Pourquoi ce cas est massif, et non marginal
 *
 * `2026_02_11_000002_add_institution_id_to_all_tables` a ajouté la colonne
 * **nullable et sans backfill**, et `2026_08_15_140000_add_institution_id_foreign_keys`
 * l'a délibérément laissée nullable (comptes plateforme). **Toute** ligne
 * antérieure à février 2026 porte donc `institution_id = NULL`.
 *
 * Une telle ligne est invisible à `$quiz->attempts()` dès qu'un tenant est
 * résolu — mais l'index unique, lui, la voit. `max + 1` re-proposait alors un
 * numéro déjà pris : violation d'unicité, aucune tentative reprenable, **409
 * définitif** pour cet étudiant sur ce quiz.
 *
 * ## Ce test DOIT passer par un vrai jeton Bearer
 *
 * `Sanctum::actingAs()` ne pose aucun jeton. Or `ResolveInstitution` commence
 * par `TenantManager::reset()` puis résout l'institution **depuis le jeton
 * porteur** : sans jeton, le tenant reste nul, le global scope est désactivé,
 * et un test « vert » ne prouverait strictement rien du comportement de
 * production. D'où `createToken()` + en-tête `Authorization`.
 */
final class KnowledgeCheckLegacyTenantAttemptTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;
    private KnowledgeCheck $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
        $this->quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $chapter->id,
            'institution_id' => $this->institution->id,
            'is_active' => true,
            'max_attempts' => 5,
        ]);
    }

    public function test_une_tentative_heritee_sans_institution_ne_bloque_pas_la_suivante(): void
    {
        $this->seedLegacyAttempt(attemptNumber: 1);

        $this->submitWithResolvedTenant()->assertStatus(200);

        $this->assertSame(
            [1, 2],
            DB::table('knowledge_check_attempts')->orderBy('id')
                ->pluck('attempt_number')->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    /**
     * Le quota doit lui aussi compter les lignes héritées : une tentative qui
     * existe physiquement a consommé un essai, que le tenant courant la voie
     * ou non.
     */
    public function test_une_tentative_heritee_sans_institution_compte_dans_le_quota(): void
    {
        $this->quiz->forceFill(['max_attempts' => 1])->save();
        $this->seedLegacyAttempt(attemptNumber: 1);

        $this->submitWithResolvedTenant()->assertStatus(400);

        $this->assertSame(1, DB::table('knowledge_check_attempts')->count());
    }

    /**
     * Soumet avec un VRAI jeton Bearer, pour que `ResolveInstitution` résolve
     * l'institution et active le global scope — comme en production.
     */
    private function submitWithResolvedTenant(): TestResponse
    {
        $token = $this->student->createToken('legacy-tenant-540')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", [
                'answers' => ['0' => '4', '1' => 'True'],
                'time_spent_seconds' => 10,
            ]);
    }

    /** Tentative « d'avant février 2026 » : institution_id resté à NULL. */
    private function seedLegacyAttempt(int $attemptNumber): void
    {
        KnowledgeCheckAttempt::create([
            'knowledge_check_id' => $this->quiz->id,
            'user_id' => $this->student->id,
            'institution_id' => null,
            'attempt_number' => $attemptNumber,
            'score' => 40,
            'correct_answers' => 1,
            'total_questions' => 2,
            'answers' => [],
            'time_spent_seconds' => 20,
            'passed' => false,
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
    }
}
