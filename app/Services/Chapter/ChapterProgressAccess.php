<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lesson\LessonProgressGate;

/**
 * Accès à la progression des chapitres, pour ChapterProgressService (#934) :
 * traduit les refus de {@see LessonProgressGate} dans la forme
 * `{status, payload}` que ce service rend, AVANT toute ligne créée.
 *
 * Une ressource absente et une ressource illisible rendent le MÊME 404.
 */
final class ChapterProgressAccess
{
    public function __construct(
        private readonly LessonProgressGate $gate,
        private readonly ChapterAccessGate $sequence,
    ) {}

    /**
     * Le chapitre, ou la réponse de refus. Pour la complétion (`$sequentiel`),
     * l'ordre des chapitres en plus.
     *
     * @return Chapter|array{status:int, payload: array<string, mixed>}
     */
    public function chapitre(int $chapterId, User $user, bool $ecriture, bool $sequentiel = false): Chapter|array
    {
        $chapter = Chapter::find($chapterId);
        $refus = $ecriture
            ? $this->gate->refusEcritureChapitre($chapter, $user)
            : $this->gate->refusLectureChapitre($chapter, $user);

        if ($refus === null && $chapter !== null) {
            if (! $sequentiel || $this->sequence->canAccess($user->id, $chapter)) {
                return $chapter;
            }
            $refus = ['status' => 403, 'message' => 'Vous devez d\'abord completer le chapitre precedent'];
        }

        return self::reponse($refus ?? ['status' => 404, 'message' => 'Chapitre introuvable']);
    }

    /** @return array{status:int, payload: array<string, mixed>}|null  null : lecture permise. */
    public function refusLectureLecon(int $lessonId, User $user): ?array
    {
        $refus = $this->gate->refusLectureLecon(Lesson::find($lessonId), $user);

        return $refus === null ? null : self::reponse($refus);
    }

    /**
     * @param  array{status: int, message: string}  $refus
     * @return array{status:int, payload: array<string, mixed>}
     */
    private static function reponse(array $refus): array
    {
        return ['status' => $refus['status'], 'payload' => ['success' => false, 'message' => $refus['message']]];
    }
}
