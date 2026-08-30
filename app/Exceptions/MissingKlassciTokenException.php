<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Le jeton KLASSCI personnel de l'utilisateur est réellement absent.
 *
 * À ne PAS confondre avec une erreur renvoyée par KLASSCI alors que le jeton est
 * bien présent et transmis. C'est précisément cette confusion que cette classe
 * existe pour lever.
 *
 * Avant elle, les contrôleurs attrapaient un `RuntimeException` nu — que lève
 * aussi {@see \App\Services\Klassci\KlassciHttpClient} sur toute réponse 4xx de
 * KLASSCI — et répondaient invariablement :
 *
 *     401  « Token KLASSCI non trouvé. Veuillez vous reconnecter. »
 *
 * Un utilisateur sans profil enseignant côté KLASSCI (KLASSCI répond alors 404
 * « Profil enseignant introuvable ») recevait donc un message désignant une
 * cause fausse, avec un code 401 que le frontend interprète comme une session
 * expirée. Il se déconnectait et se reconnectait en boucle, sans jamais
 * comprendre — et le vrai motif ne figurait que dans les logs serveur.
 *
 * Règle : seul un jeton **absent** lève cette exception. Une erreur venue de
 * KLASSCI reste un `RuntimeException` porteur du statut HTTP d'origine, que les
 * contrôleurs relaient tel quel.
 */
final class MissingKlassciTokenException extends RuntimeException
{
    public const CLIENT_MESSAGE = 'Session KLASSCI expirée. Veuillez vous reconnecter.';

    public static function forUser(?int $userId = null): self
    {
        return new self(
            $userId === null
                ? 'Jeton KLASSCI absent pour l\'utilisateur authentifié.'
                : "Jeton KLASSCI absent pour l'utilisateur {$userId}.",
            401
        );
    }
}
