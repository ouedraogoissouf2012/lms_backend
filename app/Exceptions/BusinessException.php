<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

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
 *
 * ## Le motif (#906, ADR-906-01)
 *
 * Le front n'affiche jamais le message du serveur : il traduit par son propre
 * catalogue. Quand un même statut porte plusieurs sens, le motif `reason` les
 * départage — un identifiant stable, `snake_case` anglais, comme
 * `klassci_session_expired`. Optionnel : un refus qui n'a qu'un sens n'en a
 * pas besoin.
 */
final class BusinessException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
