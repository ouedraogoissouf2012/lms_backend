<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier dont le `getMessage()` est explicitement safe pour le client.
 *
 * Sépare clairement les erreurs métier (dont le message peut être renvoyé tel
 * quel au client) des `RuntimeException` génériques (potentielle fuite de
 * détails techniques si exposées).
 *
 * Usage : les services lèvent une `BusinessException` pour les invariants
 * métier ("Impossible de désactiver la dernière institution active",
 * "URL KLASSCI non configurée"). Le controller catch SPÉCIFIQUEMENT cette
 * classe pour relayer `$e->getMessage()` au client. Les autres `Throwable`
 * tombent dans le catch générique → message générique + log serveur.
 */
final class BusinessException extends RuntimeException
{
}
