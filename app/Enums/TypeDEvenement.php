<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le type d'un événement du calendrier de l'année (#1671), une énumération FERMÉE (ARRÊT 1, Q2). Le produit est
 * 100 % en ligne : aucun type ne désigne un lieu. `vacances` et `fermeture` marquent des jours fermés, que la
 * génération des séances saute (PR de suite de #1669).
 */
enum TypeDEvenement: string
{
    case Rentree = 'rentree';
    case Orientation = 'orientation';
    case Examens = 'examens';
    case Vacances = 'vacances';
    case Reprise = 'reprise';
    case Soutenances = 'soutenances';
    case Ceremonie = 'ceremonie';
    case Fermeture = 'fermeture';
    case Autre = 'autre';

    /** Les jours de cet événement sont-ils fermés (aucune séance) ? */
    public function fermeLesJours(): bool
    {
        return $this === self::Vacances || $this === self::Fermeture;
    }
}
