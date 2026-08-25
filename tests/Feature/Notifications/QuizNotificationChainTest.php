<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizAttemptTeacherGradeService;
use App\Services\Quiz\QuizCrudService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #500 — la chaîne de notifications quiz était intégralement morte : les 3
 * méthodes de {@see \App\Services\Notification\QuizNotificationDispatcher}
 * n'avaient aucun appelant, et rien d'autre n'émettait de notification quiz.
 *
 * Un enseignant pouvait corriger une copie sans que l'étudiant en soit jamais
 * informé, alors que le type `grade_received` et son rendu front existaient
 * déjà (`NotificationPresenter:86-113`).
 *
 * Ces tests verrouillent les trois émissions désormais câblées, ainsi que les
 * trois défauts de schéma que le dispatcher aurait diffusés à des utilisateurs
 * réels une fois branché (colonnes `max_score` / `percentage` inexistantes,
 * statut `completed` absent de l'énumération).
 *
 * @see .claude/specs/500-notif-chain/design.md
 */
final class QuizNotificationChainTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
    }

    public function test_manual_grading_notifies_the_student(): void
    {
        [$quiz, $student, $attempt] = $this->attemptAwaitingGrading();

        $this->app->make(QuizAttemptTeacherGradeService::class)
            ->manualGrade($attempt, $this->teacher, 15.0, 'Bon travail');

        $notification = Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_GRADE_RECEIVED)
            ->first();

        self::assertNotNull($notification, "L'étudiant n'a reçu aucune notification de note.");
        self::assertSame($quiz->id, $notification->data['quiz_id']);
    }

    /**
     * Le message se construisait sur `max_score` et `percentage`, deux
     * attributs qui n'existent pas sur `quiz_attempts` : il rendait
     * « note de 15.00/ » et un `percentage` à `null`.
     */
    public function test_grade_notification_carries_real_points_and_percentage(): void
    {
        [, $student, $attempt] = $this->attemptAwaitingGrading();

        $this->app->make(QuizAttemptTeacherGradeService::class)
            ->manualGrade($attempt, $this->teacher, 15.0, null);

        $notification = Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_GRADE_RECEIVED)
            ->firstOrFail();

        // 15 points sur 20 possibles = 75 % (QuizGradingService:229).
        self::assertStringContainsString('15', (string) $notification->message);
        self::assertStringContainsString('20', (string) $notification->message);
        self::assertStringNotContainsString('/"', (string) $notification->message);
        self::assertSame(75.0, (float) $notification->data['percentage']);
        self::assertSame(20.0, (float) $notification->data['max_score']);
    }

    public function test_publishing_a_quiz_notifies_the_class(): void
    {
        $enrolled = $this->studentInClasse();
        $outsider = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);

        $quiz = $this->quizForClasse(['status' => 'draft', 'published_at' => null]);
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $this->app->make(QuizCrudService::class)->publish($quiz);

        self::assertSame(1, Notification::query()
            ->where('user_id', $enrolled->id)
            ->where('type', Notification::TYPE_QUIZ_AVAILABLE)
            ->count());
        self::assertSame(0, Notification::query()
            ->where('user_id', $outsider->id)
            ->where('type', Notification::TYPE_QUIZ_AVAILABLE)
            ->count());
    }

    /**
     * Le filtre `status = 'completed'` n'existe pas dans l'énumération
     * (`in_progress|submitted|graded|abandoned`) : l'exclusion « a déjà
     * participé » était morte et rappelait aussi ceux qui avaient rendu.
     */
    public function test_deadline_reminder_skips_students_who_already_finished(): void
    {
        $pending = $this->studentInClasse();
        $finished = $this->studentInClasse();

        $quiz = $this->quizForClasse(['available_until' => now()->addHours(12)]);
        QuizAttempt::factory()->forQuiz($quiz)->byUser($finished)->create(['status' => 'graded']);

        $this->runDeadlineCommandFromCli();

        self::assertSame(1, $this->deadlineCountFor($pending));
        self::assertSame(0, $this->deadlineCountFor($finished), 'Rappel envoyé à un étudiant ayant déjà rendu.');
    }

    public function test_deadline_command_does_not_notify_twice_the_same_day(): void
    {
        $student = $this->studentInClasse();
        $this->quizForClasse(['available_until' => now()->addHours(12)]);

        $this->runDeadlineCommandFromCli();
        $this->runDeadlineCommandFromCli();

        self::assertSame(1, $this->deadlineCountFor($student));
    }

    /**
     * Exécutée par le cron, la commande n'a aucun tenant résolu. Sans
     * restauration explicite, la notification serait écrite avec
     * `institution_id = NULL` et le scope global la masquerait à la lecture
     * HTTP — elle existerait sans être jamais vue (famille #579).
     */
    public function test_deadline_command_sets_institution_on_notifications(): void
    {
        $student = $this->studentInClasse();
        $this->quizForClasse(['available_until' => now()->addHours(12)]);

        $this->runDeadlineCommandFromCli();

        // Relecture dans un contexte HTTP : le scope global doit la laisser passer.
        $this->app->make(TenantManager::class)->set($this->institution);

        self::assertSame(1, $this->deadlineCountFor($student));
    }

    /**
     * Un étudiant désactivé (soft-deleted) ne casse pas la correction.
     *
     * `QuizAttempt::user()` est un `belongsTo` nu et `User` porte `SoftDeletes` :
     * la relation renvoie `null`. Sans garde, la note était persistée puis
     * l'envoi levait un TypeError → 500 permanent sur l'endpoint de notation.
     */
    public function test_grading_a_deactivated_student_does_not_crash(): void
    {
        [, $student, $attempt] = $this->attemptAwaitingGrading();
        $student->delete();

        $result = $this->app->make(QuizAttemptTeacherGradeService::class)
            ->manualGrade($attempt->fresh(), $this->teacher, 15.0, null);

        self::assertSame(200, $result['status']);
        self::assertSame(0, Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_GRADE_RECEIVED)
            ->count());
    }

    /** Republier un quiz déjà publié ne renotifie pas la classe. */
    public function test_republishing_does_not_notify_the_class_again(): void
    {
        $student = $this->studentInClasse();
        $quiz = $this->quizForClasse(['status' => 'draft', 'published_at' => null]);
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $service = $this->app->make(QuizCrudService::class);
        $service->publish($quiz);
        $service->publish($quiz->fresh());

        self::assertSame(1, Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_QUIZ_AVAILABLE)
            ->count());
    }

    /**
     * Un quiz publié mais dont l'ouverture est programmée plus tard ne doit pas
     * être annoncé « maintenant disponible » — ce serait factuellement faux.
     */
    public function test_publishing_a_quiz_scheduled_for_later_does_not_announce_it_available(): void
    {
        $student = $this->studentInClasse();
        $quiz = $this->quizForClasse([
            'status' => 'draft',
            'published_at' => null,
            'available_from' => now()->addWeek(),
        ]);
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $this->app->make(QuizCrudService::class)->publish($quiz);

        self::assertSame(0, Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_QUIZ_AVAILABLE)
            ->count());
    }

    /**
     * Le pivot `classe_etudiant.statut` fait foi, comme pour la visio
     * (`VisioNotificationDispatcher:152`) et `Classe::etudiantsActifs()`.
     */
    public function test_only_actively_enrolled_students_are_notified(): void
    {
        $active = $this->studentInClasse();
        $dropped = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->classe->etudiants()->attach($dropped->id, ['statut' => 'inactif']);

        $quiz = $this->quizForClasse(['status' => 'draft', 'published_at' => null]);
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id]);

        $this->app->make(QuizCrudService::class)->publish($quiz);

        self::assertSame(1, $this->availableCountFor($active));
        self::assertSame(0, $this->availableCountFor($dropped), 'Étudiant désinscrit notifié.');
    }

    /**
     * `--days` est la fenêtre de balayage, pas le délai restant : un quiz qui
     * ferme dans 12 h ne doit pas annoncer « dans 7 jours ».
     */
    public function test_deadline_message_reflects_the_actual_remaining_time(): void
    {
        $student = $this->studentInClasse();
        $this->quizForClasse(['available_until' => now()->addHours(12)]);

        $this->app->make(TenantManager::class)->reset();
        $this->artisan('quiz:notify-deadlines --days=7')->assertSuccessful();
        $this->app->make(TenantManager::class)->set($this->institution);

        $message = (string) Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_QUIZ_DEADLINE)
            ->firstOrFail()
            ->message;

        self::assertStringNotContainsString('7 jours', $message);
        self::assertStringContainsString('1 jour', $message);
    }

    /** Exécute la commande sans tenant résolu, comme le ferait le cron. */
    private function runDeadlineCommandFromCli(): void
    {
        $this->app->make(TenantManager::class)->reset();
        $this->artisan('quiz:notify-deadlines')->assertSuccessful();
    }

    private function availableCountFor(User $student): int
    {
        return Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_QUIZ_AVAILABLE)
            ->count();
    }

    private function deadlineCountFor(User $student): int
    {
        return Notification::query()
            ->where('user_id', $student->id)
            ->where('type', Notification::TYPE_QUIZ_DEADLINE)
            ->count();
    }

    private function studentInClasse(): User
    {
        $student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->classe->etudiants()->attach($student->id, ['statut' => 'actif']);

        return $student;
    }

    /** @param array<string, mixed> $attributes */
    private function quizForClasse(array $attributes = []): Quiz
    {
        return Quiz::factory()->forTeacher($this->teacher)->create($attributes + [
            'institution_id' => $this->institution->id,
            'classe_id' => $this->classe->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    /** @return array{0: Quiz, 1: User, 2: QuizAttempt} */
    private function attemptAwaitingGrading(): array
    {
        $student = $this->studentInClasse();
        $quiz = $this->quizForClasse();
        $attempt = QuizAttempt::factory()->forQuiz($quiz)->byUser($student)->create([
            'status' => 'submitted',
            'score' => null,
            'points_earned' => null,
            'points_possible' => 20,
            'graded_at' => null,
        ]);

        return [$quiz, $student, $attempt];
    }
}
