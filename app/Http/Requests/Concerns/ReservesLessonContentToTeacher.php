<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Search\TeacherOwnershipScope;
use App\Support\Lesson\LessonContentTeacherOnly;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Les écritures sur le contenu d'une leçon (#1167) : l'enseignant auteur, et lui seul.
 *
 * L'auteur se lit à UNE source, {@see TeacherOwnershipScope::ownsLesson()} ; le rôle
 * reste chez l'appelant, ici. Le port est reçu à l'appel (`authorize()` est résolu
 * par le conteneur), comme `after()` reçoit le sien.
 *
 * Deux refus, deux sens du même 403 (ADR-906-01) :
 * - un compte qui n'est pas enseignant (coordinateur, admin d'école) reçoit le motif
 *   {@see LessonContentTeacherOnly::REASON} ;
 * - un enseignant qui n'est pas l'auteur, ou une leçon d'une autre école, garde le
 *   refus générique.
 *
 * Utilisé par une FormRequest : {@see failedAuthorization()} remplace celle de
 * Laravel. Une requête qui a déjà la sienne appelle d'abord
 * {@see refuserUnCompteNonEnseignant()}.
 */
trait ReservesLessonContentToTeacher
{
    /**
     * Le compte est un enseignant, auteur de cette leçon de son établissement. Le
     * filtre d'établissement est explicite : le scope global est ouvert quand aucun
     * tenant n'est résolu.
     */
    protected function enseignantAuteurDeLaLecon(mixed $leconId, TeacherOwnershipScope $auteurs): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! is_numeric($leconId)) {
            return false;
        }

        $lecon = Lesson::query()
            ->whereKey((int) $leconId)
            ->where('institution_id', $user->institution_id)
            ->first();

        return $lecon instanceof Lesson && $this->enseignantAuteur($lecon, $user, $auteurs);
    }

    /** La règle d'écriture du contenu : un enseignant, auteur de la leçon. */
    protected function enseignantAuteur(Lesson $lecon, User $user, TeacherOwnershipScope $auteurs): bool
    {
        return $user->isTeacher() && $auteurs->ownsLesson($lecon, $user);
    }

    protected function failedAuthorization(): void
    {
        $this->refuserUnCompteNonEnseignant();

        parent::failedAuthorization();
    }

    /** Un compte authentifié qui n'est pas enseignant : le refus motivé. */
    protected function refuserUnCompteNonEnseignant(): void
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->isTeacher()) {
            throw new HttpResponseException(LessonContentTeacherOnly::jsonResponse());
        }
    }
}
