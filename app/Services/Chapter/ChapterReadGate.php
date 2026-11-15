<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Enrollment\InscriptionNonActive;

/**
 * #621 — lecture d'un chapitre : même frontière que #482 (classe de l'étudiant).
 *
 * Staff et propriétaire voient tout le tenant. Un étudiant ne voit que les
 * chapitres d'une leçon publiée dont `classe_id` est dans SES classes.
 * Hors classe → inexistant (404), jamais 403 (oracle d'énumération).
 *
 * #1095 : un étudiant qu'une inscription ACTIVE dans la classe de la leçon aurait
 * admis, mais dont la ligne n'est pas active (D57), reçoit
 * {@see LectureDeLecon::InscriptionNonActive} : il n'a toujours aucun accès, et son
 * refus dit ce motif. La ressource ne se révèle qu'à l'élève de SA classe.
 */
final class ChapterReadGate
{
    public function __construct(
        private readonly EnrollmentSource $classes,
    ) {
    }

    public function canRead(?Chapter $chapter, ?User $user): bool
    {
        return $this->lecture($chapter, $user) === LectureDeLecon::Admise;
    }

    public function canReadLesson(?Lesson $lesson, ?User $user): bool
    {
        return $this->lectureLecon($lesson, $user) === LectureDeLecon::Admise;
    }

    public function lecture(?Chapter $chapter, ?User $user): LectureDeLecon
    {
        if ($chapter === null || $user === null) {
            return LectureDeLecon::Introuvable;
        }

        if ($user->isPlatformSupradmin()) {
            return LectureDeLecon::Admise;
        }

        if ($chapter->institution_id !== $user->institution_id) {
            return LectureDeLecon::Introuvable;
        }

        if ($user->isStaff()) {
            return LectureDeLecon::Admise;
        }

        $lesson = $chapter->relationLoaded('lesson') ? $chapter->lesson : $chapter->lesson()->first();

        return $this->lectureLecon($lesson, $user);
    }

    public function lectureLecon(?Lesson $lesson, ?User $user): LectureDeLecon
    {
        if ($lesson === null || $user === null) {
            return LectureDeLecon::Introuvable;
        }

        if ($user->isPlatformSupradmin()) {
            return LectureDeLecon::Admise;
        }

        if ($lesson->institution_id !== $user->institution_id) {
            return LectureDeLecon::Introuvable;
        }

        if ($user->isStaff()) {
            return LectureDeLecon::Admise;
        }

        if (! $user->isStudent() || ! $lesson->isPublished() || $lesson->classe_id === null) {
            return LectureDeLecon::Introuvable;
        }

        if (in_array((int) $lesson->classe_id, $this->classes->localClasseIdsFor($user), true)) {
            return LectureDeLecon::Admise;
        }

        return $this->inscriptionNonActive((int) $lesson->classe_id, $user)
            ? LectureDeLecon::InscriptionNonActive
            : LectureDeLecon::Introuvable;
    }

    /** Une ligne non active dans CETTE classe, bornée EXPLICITEMENT à son établissement. */
    private function inscriptionNonActive(int $classeId, User $user): bool
    {
        return InscriptionNonActive::classes(
            Classe::query()->where('institution_id', $user->institution_id)->whereKey($classeId),
            $user,
        )->exists();
    }
}
