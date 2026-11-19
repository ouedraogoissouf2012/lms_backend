<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * La réponse à « mot de passe oublié » (#808), selon l'école servie.
 *
 * La réponse dit ce qui est vrai, par un motif que le front traduit (son catalogue porte le
 * texte ; `message` en est le repli identique). Elle ne dépend JAMAIS du compte : connu ou
 * inconnu, même statut, même corps — aucune énumération des adresses.
 *
 * - École KLASSCI : le mot de passe se réinitialise dans KLASSCI ; rien ne part.
 * - École autonome : un lien part par courriel si le canal est configuré ; sinon rien ne part
 *   et la réponse ne promet rien.
 *
 * Liée par école au point de liaison (`AuthPasswordServiceProvider`, ADR-EI-01).
 */
interface MotDePasseOublie
{
    /**
     * @return array{status: int, payload: array{success: bool, reason: string, message: string}}
     */
    public function reponse(string $email): array;
}
