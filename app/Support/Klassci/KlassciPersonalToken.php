<?php

declare(strict_types=1);

namespace App\Support\Klassci;

use App\Models\User;

/**
 * Le jeton KLASSCI PERSONNEL d'un compte : celui avec lequel KLASSCI scope ses
 * données selon le porteur. Un jeton absent ou vide n'en est pas un.
 *
 * La règle est écrite ici une fois, pour le trait des contrôleurs proxy
 * (`ResolvesPersonalKlassciToken`) et pour les sources KLASSCI (ADR-EI-01), qui
 * reçoivent un compte et non une requête. D'autres sites relisent encore
 * `klassci_token` à la main : dette tracée, hors du lot 3c.
 */
final class KlassciPersonalToken
{
    public static function de(User $user): ?string
    {
        $jeton = $user->klassci_token;

        return is_string($jeton) && $jeton !== '' ? $jeton : null;
    }
}
