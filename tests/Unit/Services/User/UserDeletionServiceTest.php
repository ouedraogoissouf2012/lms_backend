<?php

declare(strict_types=1);

namespace Tests\Unit\Services\User;

use App\Models\AuditLog;
use App\Models\EvaluationSubmission;
use App\Models\ForumPost;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\User\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Issue #566 — {@see UserDeletionService} en isolation.
 *
 * Le service porte la logique métier de la suppression (comptage des liés →
 * révocation Sanctum → soft delete → audit), atomiquement. On vérifie ici les
 * invariants R3 (révocation) et R4 (audit avec décompte), le service étant
 * résolu par le container (DI stricte, aucune Facade).
 *
 * @see app/Services/User/UserDeletionService.php
 */
final class UserDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_delete_revokes_tokens_and_audits_preserved_counts(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
        $user->createToken('t1');
        $user->createToken('t2');

        // 2 tentatives sur 2 quiz distincts (l'unique (quiz,user,attempt) l'exige).
        $quizA = Quiz::factory()->create(['institution_id' => $institution->id]);
        $quizB = Quiz::factory()->create(['institution_id' => $institution->id]);
        QuizAttempt::factory()->create(['user_id' => $user->id, 'quiz_id' => $quizA->id, 'institution_id' => $institution->id]);
        QuizAttempt::factory()->create(['user_id' => $user->id, 'quiz_id' => $quizB->id, 'institution_id' => $institution->id]);

        EvaluationSubmission::factory()->count(3)->create([
            'student_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        ForumPost::factory()->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);
        Notification::factory()->count(4)->create([
            'user_id' => $user->id,
            'institution_id' => $institution->id,
        ]);

        app(UserDeletionService::class)->softDelete($user);

        // Soft delete effectif.
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        // R3 : tous les jetons révoqués.
        $this->assertSame(0, PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count());

        // R4 : entrée d'audit avec le décompte exact des enregistrements conservés.
        $audit = AuditLog::query()
            ->where('action', 'user.soft_deleted')
            ->where('auditable_id', $user->id)
            ->firstOrFail();

        $preserved = $audit->after['preserved'] ?? null;
        $this->assertIsArray($preserved);
        $this->assertSame(2, $preserved['quiz_attempts']);
        $this->assertSame(3, $preserved['evaluation_submissions']);
        $this->assertSame(1, $preserved['forum_posts']);
        $this->assertSame(4, $preserved['notifications']);

        // Les enregistrements liés sont bien préservés (aucune cascade).
        $this->assertSame(2, QuizAttempt::withoutGlobalScope('institution')->where('user_id', $user->id)->count());
        $this->assertSame(3, EvaluationSubmission::withoutGlobalScope('institution')->where('student_id', $user->id)->count());
    }
}
