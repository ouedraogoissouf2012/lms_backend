<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand KLASSCI désigne, pour un compte NOUVEAU, un rôle hors de la table du
 * LMS (`Role::tryFromString`, #949) : variante de casse, rôle propre à KLASSCI…
 *
 * Un tel rôle n'est jamais écrit en base (la production n'a que des valeurs
 * canoniques, mesuré le 17/11). La connexion est refusée avant toute écriture,
 * 403 `klassci_role_unknown`, et le synchroniseur en laisse une ligne de journal.
 * La résolution est administrative : corriger le rôle dans KLASSCI.
 *
 * Voir `KlassciUserSynchronizer::createNewUser()` et `AuthController::login()`.
 */
final class KlassciRoleInconnuException extends RuntimeException
{
    public const REASON = 'klassci_role_unknown';
}
