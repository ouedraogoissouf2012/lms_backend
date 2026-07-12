<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\Seance;
use App\Models\User;

final class VisioActorAuthorization
{
    public function canManage(Seance $seance, User $actor): bool
    {
        if ($seance->institution_id !== $actor->institution_id) {
            return false;
        }

        return $actor->isManager() || $this->teacherOwns($seance, $actor);
    }

    public function canValidate(Seance $seance, User $actor, User $target): bool
    {
        return $this->canManage($seance, $actor)
            || ($actor->isStudent() && $actor->is($target));
    }

    public function teacherOwns(Seance $seance, User $teacher): bool
    {
        if (! $teacher->isTeacher() || $seance->klassci_enseignant_id === null) {
            return false;
        }

        $ownerId = (int) $seance->klassci_enseignant_id;

        return (is_numeric($teacher->klassci_enseignant_id)
                && (int) $teacher->klassci_enseignant_id === $ownerId)
            || (is_numeric($teacher->klassci_id) && (int) $teacher->klassci_id === $ownerId);
    }
}
