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
 * Qui lit et qui écrit la progression d'une leçon (#934). Source unique des
 * décisions, que contrôleurs et services interrogent sans recopier de prédicat.
 *
 * ## Écrire une progression
 *
 * La progression est celle d'un APPRENANT : il doit pouvoir lire la leçon
 * ({@see ChapterReadGate::canReadLesson()}, la règle qui sert déjà les chapitres :
 * même établissement, publiée, de SA classe par le composite). Sinon 404, qui
 * ne révèle pas l'existence de la leçon. Le personnel n'écrit pas de
 * progression : 403. Le refus vient AVANT toute écriture : aucune ligne n'est
 * créée, et une tentative de quiz refusée ne consomme aucun essai.
 *
 * ## Lire les progressions des élèves d'une leçon
 *
 * Réservé à l'auteur de la leçon ({@see TeacherOwnershipScope::ownsLesson()}) et
 * aux gestionnaires (`isManager()`), dans son établissement. Effet voulu : un
 * co-enseignant ne lit pas les progressions de la leçon d'un collègue.
 */
final class LessonProgressGate
{
    public function __construct(
        private readonly ChapterReadGate $reads,
        private readonly TeacherOwnershipScope $ownership,
    ) {}

    /** @return array{status: int, message: string}|null  null : l'écriture est permise. */
    public function refusEcriture(Lesson $lesson, User $user): ?array
    {
        if ($user->isStaff()) {
            return self::refusPersonnel();
        }

        return $this->reads->canReadLesson($lesson, $user) ? null : ['status' => 404, 'message' => 'Cours non trouvé'];
    }

    /** @return array{status: int, message: string}|null */
    public function refusEcritureChapitre(Chapter $chapter, User $user): ?array
    {
        if ($user->isStaff()) {
            return self::refusPersonnel();
        }

        return $this->reads->canRead($chapter, $user) ? null : ['status' => 404, 'message' => 'Chapitre introuvable'];
    }

    /**
     * Un quiz de chapitre suit la règle de LECTURE de son chapitre : sans
     * chapitre lisible, il est introuvable.
     *
     * Le personnel n'est PAS refusé ici, à la différence des autres écritures :
     * l'écran de gestion des chapitres de l'enseignant
     * (`/teacher/lessons/:id/chapters`) joue le quiz en aperçu, et donc le
     * démarre et le soumet. `canRead` admet le personnel de l'établissement.
     *
     * @return array{status: int, message: string}|null
     */
    public function refusEcritureQuiz(KnowledgeCheck $quiz, User $user): ?array
    {
        $chapter = $quiz->chapter;

        return $chapter instanceof Chapter && $this->reads->canRead($chapter, $user)
            ? null
            : ['status' => 404, 'message' => 'Quiz introuvable'];
    }

    public function peutLireLesProgressions(Lesson $lesson, User $user): bool
    {
        $memeEtablissement = $user->isPlatformSupradmin()
            || ($user->institution_id !== null && $lesson->institution_id === $user->institution_id);

        return $memeEtablissement
            && ($user->isManager() || ($user->isTeacher() && $this->ownership->ownsLesson($lesson, $user)));
    }

    /** @return array{status: int, message: string} */
    private static function refusPersonnel(): array
    {
        return ['status' => 403, 'message' => 'La progression est celle des apprenants'];
    }
}
