<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\KnowledgeCheck;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Progression user sur les chapitres d'une leçon. Extrait verbatim de
 * `ChapterProgressController` (split-24). Regroupe lecture, mutation
 * (markAsCompleted, updateTimeSpent, recordQuizScore) et reset.
 * Retourne `{status, payload}` consommé par le controller thin.
 */
final class ChapterProgressService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ChapterAccessGate $accessGate,
        private readonly LessonChapterProgressCalculator $progressCalculator,
    ) {}

    /** @return array{status:int, payload: array<string, mixed>} */
    public function getLessonProgress(int $lessonId, User $user): array
    {
        $progress = $this->progressCalculator->calculate($user->id, $lessonId);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => $progress,
            ],
        ];
    }

    /**
     * Détail progression chapitre. Inclut vérif d'accès + descripteur quiz
     * bloquant si accès refusé.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function getChapterProgress(int $chapterId, User $user): array
    {
        $userId = $user->id;
        $chapter = Chapter::findOrFail($chapterId);

        $progress = ChapterProgress::where('user_id', $userId)
            ->where('chapter_id', $chapterId)
            ->first();

        $canAccess = $this->accessGate->canAccess($userId, $chapter);

        $blockedByQuiz = null;
        if (! $canAccess) {
            $blockedByQuiz = $this->accessGate->blockingQuiz($chapter);
        }

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => [
                    'chapter_id' => $chapterId,
                    'is_completed' => $progress?->isCompleted() ?? false,
                    'completed_at' => $progress?->completed_at?->toIso8601String(),
                    'time_spent_seconds' => $progress?->time_spent_seconds ?? 0,
                    'quiz_score' => $progress?->quiz_score,
                    'quiz_passed' => $progress?->quiz_passed ?? false,
                    'can_access' => $canAccess,
                    'blocked_by_quiz' => $blockedByQuiz,
                ],
            ],
        ];
    }

    /**
     * Marque un chapitre complété. Valide accès (chapitre précédent) +
     * quiz obligatoire réussi si type=quiz.
     *
     * @param  array<string, mixed>  $input  `time_spent_seconds` optionnel.
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function markAsCompleted(int $chapterId, User $user, array $input): array
    {
        $userId = $user->id;
        $chapter = Chapter::findOrFail($chapterId);

        if (! $this->accessGate->canAccess($userId, $chapter)) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Vous devez d\'abord completer le chapitre precedent',
                ],
            ];
        }

        if ($chapter->content_type === 'quiz') {
            $quiz = KnowledgeCheck::where('chapter_id', $chapterId)
                ->where('is_active', true)
                ->first();

            if ($quiz && $quiz->is_required) {
                $progressForQuiz = ChapterProgress::where('user_id', $userId)
                    ->where('chapter_id', $chapterId)
                    ->first();

                if (! $progressForQuiz || ! $progressForQuiz->quiz_passed) {
                    return [
                        'status' => 400,
                        'payload' => [
                            'success' => false,
                            'message' => 'Vous devez reussir le quiz pour completer ce chapitre',
                        ],
                    ];
                }
            }
        }

        $progress = $this->getOrCreateProgress($userId, $chapterId);

        $progress->completed_at = now();
        $progress->save();

        if (array_key_exists('time_spent_seconds', $input)) {
            $added = (int) ($input['time_spent_seconds'] ?? 0);
            if ($added > 0) {
                $progress->increment('time_spent_seconds', $added);
            }
        }

        $lessonProgress = $this->progressCalculator->calculate($userId, $chapter->lesson_id);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Chapitre marque comme complete',
                'data' => [
                    'chapter_progress' => [
                        'is_completed' => true,
                        'completed_at' => $progress->completed_at?->toIso8601String(),
                    ],
                    'lesson_progress' => $lessonProgress,
                ],
            ],
        ];
    }

    /** Enregistre score quiz (best-score wins + completed si réussi). */
    public function recordQuizScore(int $chapterId, int $score, bool $passed, User $user): void
    {
        $progress = $this->getOrCreateProgress($user->id, $chapterId);

        if ($progress->quiz_score === null || $score > $progress->quiz_score) {
            $progress->quiz_score = $score;
        }

        if ($passed) {
            $progress->quiz_passed = true;
            $progress->completed_at = now();
        }

        $progress->save();
    }

    /**
     * Cumule le temps passé sur un chapitre (validation côté appelant
     * pour le moment — préserve `Request::validate()` du controller).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function updateTimeSpent(int $chapterId, int $additionalSeconds, User $user): array
    {
        // findOrFail conservé pour parité (404 si chapitre inexistant)
        Chapter::findOrFail($chapterId);

        $progress = $this->getOrCreateProgress($user->id, $chapterId);
        if ($additionalSeconds > 0) {
            $progress->increment('time_spent_seconds', $additionalSeconds);
        }

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => [
                    'time_spent_seconds' => $progress->time_spent_seconds,
                ],
            ],
        ];
    }

    /**
     * Réinitialise la progression d'une leçon (admin/enseignant).
     *
     * - Admin : peut réinitialiser n'importe quel utilisateur via
     *   `user_id` ; sinon réinitialise sa propre progression.
     * - Enseignant : ne peut réinitialiser que sa propre progression.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function resetLessonProgress(int $lessonId, User $user, ?int $targetUserId): array
    {
        if (! $user->isAdmin() && ! $user->isTeacher()) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Non autorise',
                ],
            ];
        }

        $targetUserId ??= $user->id;

        if (! $user->isAdmin() && $targetUserId !== $user->id) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Non autorise a reinitialiser la progression d\'un autre utilisateur',
                ],
            ];
        }

        try {
            $chapters = Chapter::where('lesson_id', $lessonId)->pluck('id');

            ChapterProgress::where('user_id', $targetUserId)
                ->whereIn('chapter_id', $chapters)
                ->delete();

            $this->logger->info('Progression de leçon réinitialisée', [
                'lesson_id' => $lessonId,
                'target_user_id' => $targetUserId,
                'actor_id' => $user->id,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Progression reinitialised',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur réinitialisation progression leçon', [
                'lesson_id' => $lessonId,
                'target_user_id' => $targetUserId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }


    /**
     * Récupère ou crée la progression user×chapitre —
     * ex-`ChapterProgress::getOrCreate` (H2 §5).
     */
    private function getOrCreateProgress(int $userId, int $chapterId): ChapterProgress
    {
        return ChapterProgress::firstOrCreate(
            ['user_id' => $userId, 'chapter_id' => $chapterId],
            ['time_spent_seconds' => 0]
        );
    }
}
