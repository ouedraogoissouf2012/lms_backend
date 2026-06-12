<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\KnowledgeCheck;

/**
 * Règle d'accès séquentiel aux chapitres — ex-`ChapterProgress::canAccessChapter`
 * (H2 audit, §5 : jamais de requêtes DB dans un modèle).
 *
 * Un chapitre est accessible si le chapitre précédent est complété, ou si le
 * précédent est un quiz obligatoire réussi. Le premier chapitre est toujours
 * accessible.
 */
final class ChapterAccessGate
{
    /**
     * L'utilisateur peut-il accéder à ce chapitre ?
     */
    public function canAccess(int $userId, Chapter $chapter): bool
    {
        // Fix E2E #211 flow 3 : la colonne DB est `order` (pas `position`,
        // qui n'a jamais existe) — l'attribut inexistant valait toujours null
        // et le verrou quiz obligatoire ne bloquait JAMAIS rien.
        // Si order est null ou 0, c'est le premier chapitre - toujours accessible
        if ($chapter->order === null || $chapter->order === 0) {
            return true;
        }

        $previousChapter = Chapter::where('lesson_id', $chapter->lesson_id)
            ->whereNotNull('order')
            ->where('order', '<', $chapter->order)
            ->orderBy('order', 'desc')
            ->first();

        if (!$previousChapter) {
            return true;
        }

        // Quiz obligatoire sur le chapitre précédent → doit être réussi
        if ($previousChapter->content_type === 'quiz') {
            $quiz = KnowledgeCheck::where('chapter_id', $previousChapter->id)
                ->where('is_active', true)
                ->first();

            if ($quiz && $quiz->is_required) {
                $progress = ChapterProgress::where('user_id', $userId)
                    ->where('chapter_id', $previousChapter->id)
                    ->first();

                return $progress !== null && (bool) $progress->quiz_passed;
            }
        }

        // Sinon le chapitre précédent doit être complété
        $previousProgress = ChapterProgress::where('user_id', $userId)
            ->where('chapter_id', $previousChapter->id)
            ->first();

        return $previousProgress !== null && $previousProgress->completed_at !== null;
    }

    /**
     * Descripteur du quiz obligatoire bloquant l'accès — null si aucun
     * chapitre précédent n'est de type quiz obligatoire actif.
     *
     * @return array{chapter_id:int, chapter_title:string, quiz_title:string, passing_score:int}|null
     */
    public function blockingQuiz(Chapter $chapter): ?array
    {
        $previousChapter = Chapter::where('lesson_id', $chapter->lesson_id)
            ->where('order', '<', $chapter->order)
            ->orderBy('order', 'desc')
            ->first();

        if (! $previousChapter || $previousChapter->content_type !== 'quiz') {
            return null;
        }

        $quiz = KnowledgeCheck::where('chapter_id', $previousChapter->id)
            ->where('is_active', true)
            ->where('is_required', true)
            ->first();

        if (! $quiz) {
            return null;
        }

        return [
            'chapter_id' => $previousChapter->id,
            'chapter_title' => $previousChapter->title,
            'quiz_title' => $quiz->title,
            'passing_score' => $quiz->passing_score,
        ];
    }
}
