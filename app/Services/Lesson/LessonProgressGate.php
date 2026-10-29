<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Chapter;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Chapter\ChapterReadGate;
use App\Services\Search\TeacherOwnershipScope;

/**
 * Qui lit et qui écrit la progression d'une leçon (#934). Contrôleurs et
 * services interrogent cette porte au lieu de recopier un prédicat.
 *
 * ## Écrire une progression
 *
 * La progression est celle d'un APPRENANT : il doit pouvoir lire la leçon
 * ({@see ChapterReadGate::canReadLesson()}, la règle qui sert déjà les
 * chapitres : même établissement, publiée, de SA classe par le composite).
 * L'ordre des refus est fixe :
 * 1. hors de son établissement, ou ressource absente : 404, toujours le même
 *    corps, qui ne révèle pas l'existence de la ressource ;
 * 2. le personnel n'écrit pas de progression : 403, sauf l'aperçu d'un quiz
 *    (qui ne persiste que la tentative, jamais la progression) ;
 * 3. un apprenant qui ne lit pas la leçon : 404.
 * Le refus vient AVANT toute écriture : aucune ligne n'est créée, et une
 * tentative de quiz refusée ne consomme aucun essai.
 *
 * ## Lire
 *
 * - La progression d'une leçon ou d'un chapitre, la sienne : la règle de
 *   lecture de la leçon ou du chapitre, sinon 404.
 * - La liste des progressions des élèves d'une leçon : son auteur
 *   ({@see TeacherOwnershipScope::ownsLesson()}) et les gestionnaires, dans
 *   leur établissement. Effet voulu : un co-enseignant ne la lit pas.
 *
 * ## Dette déclarée
 *
 * Cette porte n'est PAS la source unique de la lecture des leçons : la règle de
 * {@see ChapterReadGate} (rôles de l'énumération) et celle de
 * {@see LessonReadScope} (listes et détail, audience `isLearnerAudience()`)
 * divergent encore pour un rôle hors de l'énumération. Leur unification est
 * l'objet d'un lot ultérieur.
 */
final class LessonProgressGate
{
    public function __construct(
        private readonly ChapterReadGate $reads,
        private readonly TeacherOwnershipScope $ownership,
    ) {}

    /** Le personnel n'écrit pas de progression : seule source de cette règle. */
    public function ecritLaProgression(User $user): bool
    {
        return ! $user->isStaff();
    }

    /** @return array{status: int, message: string}|null  null : l'écriture est permise. */
    public function refusEcriture(?Lesson $lesson, User $user): ?array
    {
        if ($lesson === null || ! $this->dansSonEtablissement($lesson->institution_id, $user)) {
            return self::introuvable('Cours non trouvé');
        }
        if (! $this->ecritLaProgression($user)) {
            return self::refusPersonnel();
        }

        return $this->reads->canReadLesson($lesson, $user) ? null : self::introuvable('Cours non trouvé');
    }

    /** @return array{status: int, message: string}|null */
    public function refusEcritureChapitre(?Chapter $chapter, User $user): ?array
    {
        if ($chapter === null || ! $this->dansSonEtablissement($chapter->institution_id, $user)) {
            return self::introuvable('Chapitre introuvable');
        }
        if (! $this->ecritLaProgression($user)) {
            return self::refusPersonnel();
        }

        return $this->reads->canRead($chapter, $user) ? null : self::introuvable('Chapitre introuvable');
    }

    /**
     * Un quiz de chapitre suit la règle de LECTURE de son chapitre, personnel
     * compris : l'écran de gestion des chapitres de l'enseignant
     * (`/teacher/lessons/:id/chapters`) le joue en aperçu, et relit le meilleur
     * score depuis les tentatives. La progression, elle, n'est jamais écrite
     * pour le personnel ({@see ecritLaProgression()}).
     *
     * @return array{status: int, message: string}|null
     */
    public function refusEcritureQuiz(?KnowledgeCheck $quiz, User $user): ?array
    {
        $chapter = $quiz?->chapter;

        return $chapter instanceof Chapter && $this->reads->canRead($chapter, $user)
            ? null
            : self::introuvable('Quiz introuvable');
    }

    /** @return array{status: int, message: string}|null  Lire SA progression d'une leçon. */
    public function refusLectureLecon(?Lesson $lesson, User $user): ?array
    {
        return $lesson !== null && $this->reads->canReadLesson($lesson, $user) ? null : self::introuvable('Cours non trouvé');
    }

    /** @return array{status: int, message: string}|null  Lire SA progression d'un chapitre. */
    public function refusLectureChapitre(?Chapter $chapter, User $user): ?array
    {
        return $chapter !== null && $this->reads->canRead($chapter, $user) ? null : self::introuvable('Chapitre introuvable');
    }

    /**
     * Lire la liste des progressions des élèves : hors de son établissement,
     * 404 (l'existence ne se révèle pas) ; dans l'établissement, 403 sauf pour
     * l'auteur et les gestionnaires.
     *
     * @return array{status: int, message: string}|null
     */
    public function refusLectureDesProgressions(Lesson $lesson, User $user): ?array
    {
        if (! $this->dansSonEtablissement($lesson->institution_id, $user)) {
            return self::introuvable('Cours non trouvé');
        }

        return $user->isManager() || ($user->isTeacher() && $this->ownership->ownsLesson($lesson, $user))
            ? null
            : ['status' => 403, 'message' => 'Réservé à l\'auteur de la leçon et aux gestionnaires'];
    }

    /** Le supradmin plateforme partout ; tout autre compte, dans SON établissement seulement. */
    private function dansSonEtablissement(mixed $institutionId, User $user): bool
    {
        return $user->isPlatformSupradmin()
            || ($user->institution_id !== null && $institutionId === $user->institution_id);
    }

    /** @return array{status: int, message: string} */
    private static function introuvable(string $message): array
    {
        return ['status' => 404, 'message' => $message];
    }

    /** @return array{status: int, message: string} */
    private static function refusPersonnel(): array
    {
        return ['status' => 403, 'message' => 'La progression est celle des apprenants'];
    }
}
