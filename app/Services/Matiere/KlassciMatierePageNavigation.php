<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Lesson;
use App\Models\Matiere;

/**
 * Source KLASSCI : la page matière attend le `klassci_id`. Logique déplacée
 * telle quelle depuis `LessonListService::matiereKlassciId`.
 *
 * Bornée à l'institution de la LEÇON — pas à celle du demandeur : une leçon
 * ne doit rien révéler d'une matière d'un autre établissement, même par
 * accident de données. Le `klassci_id` n'est unique que par institution
 * (#707).
 */
final class KlassciMatierePageNavigation implements MatierePageNavigation
{
    public function pageIdFor(Lesson $lesson): ?int
    {
        if ($lesson->matiere_id === null) {
            return null;
        }

        $klassciId = Matiere::query()
            ->where('id', $lesson->matiere_id)
            ->where('institution_id', $lesson->institution_id)
            ->value('klassci_id');

        return is_numeric($klassciId) ? (int) $klassciId : null;
    }
}
