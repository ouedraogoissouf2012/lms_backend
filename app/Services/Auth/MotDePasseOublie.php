<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * La réponse à « mot de passe oublié » (#808), selon l'école servie.
 *
 * Aucun courriel ne part : le LMS n'a pas d'expéditeur. La réponse dit donc ce qui est vrai,
 * par un motif que le front traduit (son catalogue porte le texte ; `message` en est le
 * repli identique). Elle ne dépend JAMAIS du compte : connu ou inconnu, même statut, même
 * corps — aucune énumération des adresses. Aucun jeton n'est créé, puisque rien ne le
 * délivrerait.
 *
 * Liée par école au point de liaison (`AuthPasswordServiceProvider`, ADR-EI-01).
 */
interface MotDePasseOublie
{
    /**
     * @return array{status: int, payload: array{success: bool, reason: string, message: string}}
     */
    public function reponse(): array;
}
