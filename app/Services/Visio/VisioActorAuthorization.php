<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\Seance;
use App\Models\User;

final class VisioActorAuthorization
{
    public const NOT_AUTHORIZED = 'actor_not_authorized';

    /**
     * Le refus rendu quand {@see canManage()} dit non : une seule forme pour les
     * routes qui gèrent une visio.
     *
     * @return array{success: false, message: string, reason: string}
     */
    public static function managementRefusal(): array
    {
        return [
            'success' => false,
            'message' => 'Vous ne pouvez pas gérer cette visio',
            'reason' => self::NOT_AUTHORIZED,
        ];
    }

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
        if (! $teacher->isTeacher()) {
            return false;
        }

        if ($seance->klassci_enseignant_id !== null) {
            $ownerId = (int) $seance->klassci_enseignant_id;

            return (is_numeric($teacher->klassci_enseignant_id)
                    && (int) $teacher->klassci_enseignant_id === $ownerId)
                || (is_numeric($teacher->klassci_id) && (int) $teacher->klassci_id === $ownerId);
        }

        return $seance->created_by !== null
            && (int) $seance->created_by === (int) $teacher->id;
    }
}
