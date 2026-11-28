<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le statut d'un enseignant dans son école (#1666). `en_activite` plutôt que le littéral réservé au statut d'une
 * inscription (garde ClasseEtudiantStatutSansLitteralTest) : les deux ne se confondent jamais.
 */
enum StatutEnseignant: string
{
    case EnActivite = 'en_activite';
    case Inactif = 'inactif';
}
