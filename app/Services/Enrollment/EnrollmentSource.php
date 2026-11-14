<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;

/**
 * Inscriptions locales (#712). Nommée par la préoccupation, pas par le mode.
 */
interface EnrollmentSource
{
    /**
     * @return list<int>
     */
    public function localClasseIdsFor(User $user): array;

    /**
     * @return list<int>
     */
    public function classeIdsForTeacher(User $teacher): array;

    /**
     * Le sens inverse de {@see localClasseIdsFor()} : les élèves dont les
     * inscriptions désignent cette classe. Pour tout élève `e` :
     * `in_array($classe->id, localClasseIdsFor($e))` ⇔ `in_array($e->id,
     * localStudentIdsOf($classe))`. L'admission et l'audience d'une séance lisent
     * donc la même règle (C40).
     *
     * @return list<int>
     */
    public function localStudentIdsOf(Classe $classe): array;
}
