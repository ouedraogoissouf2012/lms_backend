<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\SeanceDesignee;
use App\Services\Visio\VisioActorAuthorization;

/**
 * La règle de suppression d'une séance, commune à ses deux portes (#1154) :
 * `DELETE /api/lms/seances/{id}` et `DELETE /api/lms/seances/local/{id}`.
 *
 * Le personnel seulement ; un enseignant, sur une séance dont il est l'auteur.
 * La séance est celle que désigne la source REÇUE : l'espace de l'école pour la
 * porte historique (#933), l'id local pour la porte locale. Une séance que la
 * source ne désigne pas, absente ou d'une autre école, est refusée.
 */
trait AuthorizesSeanceDeletion
{
    protected function autoriseLaSuppression(SeanceDesignee $designee, VisioActorAuthorization $auteurs): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin())) {
            return false;
        }

        $seanceId = $this->route('seanceId');
        $seance = is_numeric($seanceId) ? $designee->pour((int) $seanceId, $user) : null;

        if (! $seance instanceof Seance) {
            return false;
        }

        return ! $user->isTeacher() || $auteurs->teacherOwns($seance, $user);
    }
}
