<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;

final class SeanceRecordingAccessService
{
    public function canControl(Seance $seance, User $user): bool
    {
        return $user->isTeacher() && $this->teacherOwnsSeance($seance, $user);
    }

    public function canRead(Seance $seance, User $user): bool
    {
        if ($user->isManager() || $this->teacherOwnsSeance($seance, $user)) {
            return true;
        }

        if ($seance->klassci_classe_id !== null && $this->studentBelongsToSeanceClass($seance, $user)) {
            return true;
        }

        return ESBTPAttendance::query()
            ->where('seance_id', $seance->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function teacherOwnsSeance(Seance $seance, User $user): bool
    {
        if ($seance->klassci_enseignant_id === null) {
            return false;
        }

        return in_array((int) $seance->klassci_enseignant_id, $this->userTeacherIds($user), true);
    }

    /**
     * @return list<int>
     */
    private function userTeacherIds(User $user): array
    {
        $ids = [];
        foreach (['klassci_id', 'klassci_enseignant_id'] as $key) {
            $value = $user->getAttribute($key);
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private function studentBelongsToSeanceClass(Seance $seance, User $user): bool
    {
        if (! $user->isStudent()) {
            return false;
        }

        return UserClass::query()
            ->where('institution_id', $seance->institution_id)
            ->where('user_id', $user->id)
            ->where('klassci_classe_id', $seance->klassci_classe_id)
            ->exists();
    }
}
