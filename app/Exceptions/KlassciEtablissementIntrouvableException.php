<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Connexion KLASSCI dont l'établissement est introuvable ou inactif au moment de
 * synchroniser le compte (#1181) : désactivé ou supprimé entre la découverte des
 * tenants et la synchronisation.
 *
 * Aucun compte n'est créé ni restauré : un compte d'école ne vit pas sans école.
 * La connexion est refusée, 403 `no_institution` (le motif ADR-906-01 du compte
 * sans établissement), avec une ligne de journal.
 *
 * Voir `LoginOrchestrator::buildKlassciSuccessResponse()` et `AuthController::login()`.
 */
final class KlassciEtablissementIntrouvableException extends RuntimeException
{
}
