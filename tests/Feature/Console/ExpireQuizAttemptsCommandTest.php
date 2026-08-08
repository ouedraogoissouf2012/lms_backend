<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #502 — filet de sécurité serveur des tentatives de quiz abandonnées.
 *
 * La commande `quiz:expire-attempts` existait mais n'était planifiée nulle part.
 * Scénario couvert : un étudiant démarre un quiz chronométré, sauvegarde des
 * réponses partielles, puis abandonne sans re-poller aucun endpoint → la
 * tentative restait `in_progress` indéfiniment. Ce test verrouille que la
 * commande finalise bien une tentative expirée, et laisse intacte une tentative
 * encore dans les temps.
 *
 * @see app/Console/Commands/ExpireQuizAttempts.php
 * @see routes/console.php (planification everyFiveMinutes)
 */
final class ExpireQuizAttemptsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_abandoned_expired_attempt_is_finalized(): void
    {
        $quiz = Quiz::factory()->create(['duration_minutes' => 30]);
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(40), // 40 > 30 → expirée
            'submitted_at' => null,
            'answers' => [],
        ]);

        $this->artisan('quiz:expire-attempts')->assertSuccessful();

        $fresh = $attempt->fresh();
        $this->assertNotSame(
            'in_progress',
            $fresh->status,
            'une tentative expirée abandonnée doit être finalisée par le janitor',
        );
        $this->assertNotNull($fresh->submitted_at, 'la finalisation doit poser submitted_at');
    }

    public function test_non_expired_attempt_is_left_in_progress(): void
    {
        $quiz = Quiz::factory()->create(['duration_minutes' => 30]);
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(5), // 5 < 30 → encore dans les temps
            'submitted_at' => null,
        ]);

        $this->artisan('quiz:expire-attempts')->assertSuccessful();

        $this->assertSame('in_progress', $attempt->fresh()->status);
    }
}
