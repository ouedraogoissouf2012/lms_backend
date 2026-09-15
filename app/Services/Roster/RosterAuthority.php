<?php

declare(strict_types=1);

namespace App\Services\Roster;

/**
 * Qui écrit la liste des apprenants d'un établissement (#805).
 *
 * Nommée par la préoccupation, jamais par le mode : l'appelant demande un
 * DROIT. Il ne sait pas — et ne doit pas savoir — si l'établissement est
 * branché à KLASSCI. C'est ce qui permettra d'ouvrir l'inscription locale à
 * d'autres cas sans toucher un seul contrôleur ni une seule vue.
 */
interface RosterAuthority
{
    /**
     * L'établissement courant peut-il inscrire des apprenants depuis le LMS ?
     */
    public function allowsLocalEnrolment(): bool;
}
