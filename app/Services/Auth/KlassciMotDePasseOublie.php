<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * École KLASSCI : le mot de passe est celui de KLASSCI (la connexion y retombe), c'est là qu'il
 * se réinitialise (#808).
 */
final class KlassciMotDePasseOublie implements MotDePasseOublie
{
    public const MOTIF = 'mot_de_passe_klassci';

    public function reponse(): array
    {
        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'reason' => self::MOTIF,
                'message' => 'Votre mot de passe est celui de KLASSCI : réinitialisez-le depuis KLASSCI.',
            ],
        ];
    }
}
