<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * École autonome : le mot de passe local est le seul, et aucun canal ne peut délivrer un lien de
 * réinitialisation. La réponse le dit, sans rien promettre (#808). L'envoi réel attend une
 * décision ; l'administration d'école ne peut pas non plus changer un mot de passe (#1275).
 */
final class LocalMotDePasseOublie implements MotDePasseOublie
{
    public const MOTIF = 'reinitialisation_indisponible';

    public function reponse(): array
    {
        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'reason' => self::MOTIF,
                'message' => 'La réinitialisation du mot de passe en ligne n\'est pas encore disponible.',
            ],
        ];
    }
}
