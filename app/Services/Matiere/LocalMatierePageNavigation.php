<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Lesson;

/**
 * Source locale : aucun identifiant n'est rendu sous `matiere_klassci_id`.
 *
 * La page matière y lit l'id LOCAL. Rendre ici le `klassci_id` qu'une école
 * convertie garde sur ses matières ouvrirait une autre matière ; rendre l'id
 * local sous un nom qui dit « KLASSCI » serait un mensonge que le prochain
 * lecteur paierait. Le front retombe sur `null` proprement (rechargement ou
 * retour). Naviguer par l'id local passera par une clé neutre — suite
 * déclarée du lot 1.
 */
final class LocalMatierePageNavigation implements MatierePageNavigation
{
    public function pageIdFor(Lesson $lesson): ?int
    {
        return null;
    }
}
