<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #1698 : l'état d'une case du carnet de notes (un élève × une évaluation). Seule `Rendu` porte
 * une note, et seule elle compte dans les moyennes.
 *
 * - `Rendu` : une copie FINALE (`CopiesFinales`).
 * - `ACorriger` : une copie remise dont la note attend la correction manuelle de l'enseignant.
 * - `EnRetard` : une remise refusée après l'échéance, copie verrouillée et jamais notée
 *   (décision du user, `EcheanceDeLaCopie`).
 * - `Dispense` : l'élève est dispensé de l'évaluation ; sa note, s'il en a une, ne compte nulle
 *   part (`CopiesFinales`), et l'évaluation n'est pas une note manquante.
 * - `Manquant` : rien de remis. Une tentative encore ouverte, échéance non passée, se lit aussi
 *   ainsi : le carnet ne montre que des évaluations aux résultats visibles, donc closes.
 */
enum EtatDeLaCase: string
{
    case Rendu = 'rendu';
    case ACorriger = 'a_corriger';
    case EnRetard = 'en_retard';
    case Manquant = 'manquant';
    case Dispense = 'dispense';
}
