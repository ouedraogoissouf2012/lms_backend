<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #922 (ADR-906-01) : le démarrage et la remise d'un quiz portaient plusieurs refus
 * sous un même statut (403 : indisponible ou quota atteint ; 422 : déjà remise ou
 * temps écoulé), sans motif. Chaque sens dit désormais le sien. Le 403 « tentative
 * d'un autre » reste un refus de droit, sans motif.
 *
 * Vrai jeton.
 */
final class MotifsDesRefusDuQuizTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    private User $eleve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = Institution::factory()->create();
        $this->eleve = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'etudiant', 'klassci_token' => null]);
    }

    public function test_un_quiz_indisponible_dit_son_motif(): void
    {
        $quiz = $this->quiz(['status' => 'draft', 'published_at' => null]);

        $this->asTenant($this->eleve)->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertForbidden()
            ->assertJsonPath('reason', 'quiz_unavailable');
    }

    public function test_un_quota_atteint_dit_son_motif(): void
    {
        $quiz = $this->quiz(['max_attempts' => 1]);
        QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id, 'user_id' => $this->eleve->id, 'institution_id' => $this->ecole->id, 'status' => 'submitted',
        ]);

        $this->asTenant($this->eleve)->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertForbidden()
            ->assertJsonPath('reason', 'quiz_max_attempts');
    }

    public function test_une_tentative_deja_remise_dit_son_motif(): void
    {
        $quiz = $this->quiz();
        $tentative = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id, 'user_id' => $this->eleve->id, 'institution_id' => $this->ecole->id, 'status' => 'submitted',
        ]);

        // Un corps VALIDE : sans lui, le 422 serait celui de la validation, pas le refus visé.
        $this->asTenant($this->eleve)->postJson("/api/quiz-attempts/{$tentative->id}/submit", ['answers' => ['1' => '1']])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'quiz_attempt_already_submitted');
    }

    /** @param  array<string, mixed>  $attributs */
    private function quiz(array $attributs = []): Quiz
    {
        $auteur = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant', 'klassci_token' => null]);

        return Quiz::factory()->create($attributs + [
            'institution_id' => $this->ecole->id, 'created_by' => $auteur->id, 'status' => 'published',
            'published_at' => now()->subDay(), 'available_from' => null, 'available_until' => null, 'max_attempts' => 3,
        ]);
    }
}
