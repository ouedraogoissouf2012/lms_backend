<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * KLASSCI refuse le COMPTE, pas le mot de passe (#1739) : un 403 de `POST /auth/login`, avec un
 * motif comme « Vous n'êtes pas encore réinscrit… ». Le dire « identifiants incorrects » faisait
 * croire à l'élève que son mot de passe était faux, alors qu'il doit se faire réinscrire.
 *
 * ## Le message montré est celui de KLASSCI, nettoyé
 *
 * C'est le seul texte qui dit à l'élève quoi faire, et KLASSCI l'écrit pour lui. Il passe par
 * {@see depuisLeMessageDeKlassci()} : balises retirées, espaces réduits, longueur bornée. Vide ou
 * illisible, il est remplacé par un texte à nous. Il est porté par {@see $messageAffichable}, jamais
 * par `getMessage()`, qui reste un texte de journal.
 */
final class KlassciCompteRefuse extends RuntimeException
{
    public const REASON = 'klassci_account_refused';

    public const TEXTE_PAR_DEFAUT = 'Votre établissement refuse cette connexion. Contactez son secrétariat.';

    public const LONGUEUR_MAX = 300;

    private function __construct(public readonly string $messageAffichable)
    {
        parent::__construct('KLASSCI a refusé le compte.', 403);
    }

    public static function depuisLeMessageDeKlassci(mixed $message): self
    {
        $texte = is_string($message) ? strip_tags($message) : '';
        // `/u` rend null sur un texte qui n'est pas de l'UTF-8 valide : il est alors remplacé.
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));

        return new self($texte === '' ? self::TEXTE_PAR_DEFAUT : mb_substr($texte, 0, self::LONGUEUR_MAX));
    }
}
