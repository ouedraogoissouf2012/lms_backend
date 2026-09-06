<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Seance;
use App\Models\User;

/**
 * Audience d'une séance visio (#712) : un étudiant est-il dans la classe ?
 *
 * Nommée par la préoccupation, pas par le mode. Aucun HTTP ici.
 */
interface ClasseAudienceSource
{
    public function containsStudent(Seance $seance, User $student): bool;
}
