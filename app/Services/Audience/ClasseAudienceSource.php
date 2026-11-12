<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Seance;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Audience d'une séance visio (#712) : un étudiant est-il dans la classe ? Qui
 * avertir de sa visio ? L'admission et les notifications lisent la même audience.
 *
 * Nommée par la préoccupation, pas par le mode. Aucun HTTP ici.
 */
interface ClasseAudienceSource
{
    /** La séance désigne-t-elle une classe dont l'audience peut répondre ? */
    public function designatesClasse(Seance $seance): bool;

    public function containsStudent(Seance $seance, User $student): bool;

    /**
     * Les élèves de la classe de la séance, pour les avertir de sa visio.
     *
     * @return Collection<int, User>
     */
    public function studentsOf(Seance $seance): Collection;
}
