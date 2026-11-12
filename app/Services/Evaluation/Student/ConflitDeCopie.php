<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Une violation d'unicité à la création d'une copie est-elle une COURSE entre
 * deux `/start` (D72, E2b, D188) ?
 *
 * Seul le conflit sur l'un des deux uniques des copies l'est :
 * `eval_sub_unique` `(evaluation_id, klassci_etudiant_id, attempt)` et
 * `eval_sub_student_unique` `(evaluation_id, student_id, attempt)`. Toute autre
 * violation d'unicité remonte. MySQL et PostgreSQL nomment la contrainte ;
 * SQLite cite ses colonnes.
 */
final class ConflitDeCopie
{
    public static function estUneCourse(UniqueConstraintViolationException $conflit): bool
    {
        $message = $conflit->getMessage();

        return str_contains($message, 'eval_sub_unique')
            || str_contains($message, 'eval_sub_student_unique')
            || preg_match(
                '/evaluation_submissions\.evaluation_id, evaluation_submissions\.(klassci_etudiant_id|student_id), evaluation_submissions\.attempt/',
                $message,
            ) === 1;
    }
}
