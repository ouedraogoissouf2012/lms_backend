<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une adhésion `classe_etudiant` (ADR-711-02).
 *
 * Posée par #885 pour que le code neuf cesse d'écrire `'actif'` en dur
 * (CONTRIBUTING, #522). Les occurrences antérieures ne sont PAS encore
 * migrées : c'est tracé en #896, avec leur inventaire.
 *
 * `suspendu` et `expire` n'existent que depuis que la colonne est un
 * `varchar` (`2026_09_16_140000`) : avant, l'ENUM MySQL les refusait.
 */
enum ClasseEtudiantStatut: string
{
    case Actif = 'actif';
    case Inactif = 'inactif';
    case Abandonne = 'abandonne';
    case Suspendu = 'suspendu';
    case Expire = 'expire';
}
