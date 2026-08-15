<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Issue #566 / #571 — `DELETE /api/users/{id}` DOIT être une suppression LOGIQUE.
 *
 * Aujourd'hui (RED), `$user->delete()` est un hard delete : les FK `onDelete('cascade')`
 * détruisent `quiz_attempts`, `evaluation_submissions`, `forum_posts`, `notifications`.
 * Après correctif (GREEN) : soft delete, dossier académique préservé, jetons Sanctum
 * révoqués, utilisateur invisible aux lectures courantes.
 *
 * Bearer token réel (comme {@see AdminUserTenantIsolationTest}) : sans lui, le global
 * scope `BelongsToInstitution` serait no-op et le route-model binding ne résoudrait pas
 * le tenant.
 *
 * @see app/Http/Controllers/API/AdminController.php
 * @see app/Services/User/UserDeletionService.php
 */
final class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'soft-del-566']);
        $this->coordinator = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('566')->plainTextToken];
    }

    private function studentInInstitution(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_delete_preserves_academic_records(): void
    {
        $student = $this->studentInInstitution();
        $quiz = Quiz::factory()->create(['institution_id' => $this->institution->id]);
        $attempt = QuizAttempt::factory()->create([
            'user_id' => $student->id,
            'quiz_id' => $quiz->id,
            'institution_id' => $this->institution->id,
        ]);
        $submission = EvaluationSubmission::factory()->create([
            'student_id' => $student->id,
            'institution_id' => $this->institution->id,
        ]);

        $this->withHeaders($this->bearer($this->coordinator))
            ->deleteJson("/api/users/{$student->id}")
            ->assertStatus(200);

        // RED aujourd'hui (cascade delete) → le dossier académique DOIT survivre.
        $this->assertDatabaseHas('quiz_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('evaluation_submissions', ['id' => $submission->id]);
    }

    public function test_delete_soft_deletes_the_user_row(): void
    {
        $student = $this->studentInInstitution();

        $this->withHeaders($this->bearer($this->coordinator))
            ->deleteJson("/api/users/{$student->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('users', ['id' => $student->id]);
    }

    public function test_soft_deleted_user_is_excluded_from_default_queries(): void
    {
        $student = $this->studentInInstitution();

        $this->withHeaders($this->bearer($this->coordinator))
            ->deleteJson("/api/users/{$student->id}")
            ->assertStatus(200);

        // Le SoftDeletingScope exclut la ligne des lectures normales (R2), même
        // en levant le scope institution.
        $this->assertNull(User::withoutGlobalScope('institution')->find($student->id));
    }

    public function test_second_delete_of_soft_deleted_user_returns_404(): void
    {
        $student = $this->studentInInstitution();
        $headers = $this->bearer($this->coordinator);

        $this->withHeaders($headers)->deleteJson("/api/users/{$student->id}")->assertStatus(200);

        // L'utilisateur n'existe plus pour le route-model binding (R2).
        $this->withHeaders($headers)->deleteJson("/api/users/{$student->id}")->assertStatus(404);
    }

    public function test_soft_delete_revokes_tokens_and_cuts_access(): void
    {
        $student = $this->studentInInstitution();
        $studentToken = $student->createToken('victim')->plainTextToken;

        // Sanity : le jeton fonctionne AVANT suppression.
        $this->withHeaders(['Authorization' => 'Bearer '.$studentToken])
            ->getJson('/api/user')
            ->assertStatus(200);

        // Le guard Sanctum mémoïse l'utilisateur résolu ; on le réinitialise pour
        // que la requête suivante (coordinateur) ne réutilise pas l'étudiant.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->bearer($this->coordinator))
            ->deleteJson("/api/users/{$student->id}")
            ->assertStatus(200);

        $this->app['auth']->forgetGuards();

        // R3 : jetons révoqués en base (le morph n'a pas de cascade — ils
        // resteraient sinon)…
        $this->assertSame(0, PersonalAccessToken::query()
            ->where('tokenable_type', $student->getMorphClass())
            ->where('tokenable_id', $student->id)
            ->count());

        // …et l'ancien jeton renvoie désormais 401.
        $this->withHeaders(['Authorization' => 'Bearer '.$studentToken])
            ->getJson('/api/user')
            ->assertStatus(401);
    }
}
