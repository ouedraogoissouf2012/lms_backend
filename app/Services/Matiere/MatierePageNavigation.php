<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Lesson;

/**
 * L'identifiant avec lequel le front ouvre la page de la matière d'une leçon
 * (`matiere_klassci_id`, lu par `useLessonChapters.js` après publication et au
 * retour).
 *
 * Son espace dépend de la SOURCE qui sert `lms/matieres/{id}` : il est donc
 * choisi au même point de liaison que {@see MatiereDetailsReader}, jamais
 * deviné. Sans cela, une école passée de KLASSCI à indépendante — dont les
 * matières gardent leur `klassci_id` — enverrait un id KLASSCI à une page qui
 * lit l'id LOCAL, et ouvrirait une autre matière en 200 (revue du lot 1).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
interface MatierePageNavigation
{
    /**
     * `null` quand la page ne peut pas être ouverte avec un identifiant sûr :
     * le front recharge alors la leçon ou revient en arrière.
     */
    public function pageIdFor(Lesson $lesson): ?int;
}
