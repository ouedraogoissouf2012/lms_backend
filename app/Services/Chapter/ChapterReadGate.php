<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lesson\StudentClasseResolver;

/**
 * #621 — lecture d'un chapitre : même frontière que #482 (classe de l'étudiant).
 *
 * Staff et propriétaire voient tout le tenant. Un étudiant ne voit que les
 * chapitres d'une leçon publiée dont `classe_id` est dans SES classes.
 * Hors classe → inexistant (404), jamais 403 (oracle d'énumération).
 */
final class ChapterReadGate
{
    public function __construct(
        private readonly StudentClasseResolver $classes,
    ) {
    }

    public function canRead(?Chapter $chapter, ?User $user): bool
    {
        if ($chapter === null || $user === null) {
            return false;
        }

        if ($user->role === 'supradmin') {
            return true;
        }

        if ($chapter->institution_id !== $user->institution_id) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        $lesson = $chapter->relationLoaded('lesson') ? $chapter->lesson : $chapter->lesson()->first();

        return $this->canReadLesson($lesson, $user);
    }

    public function canReadLesson(?Lesson $lesson, ?User $user): bool
    {
        if ($lesson === null || $user === null) {
            return false;
        }

        if ($user->role === 'supradmin') {
            return true;
        }

        if ($lesson->institution_id !== $user->institution_id) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        if (! $user->isStudent() || ! $lesson->isPublished() || $lesson->classe_id === null) {
            return false;
        }

        return in_array((int) $lesson->classe_id, $this->classes->localClasseIdsFor($user), true);
    }
}
