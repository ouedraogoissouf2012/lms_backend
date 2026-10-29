<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
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
 *
 * ## #806 — un jeton absent n'est pas une session expirée
 *
 * Le message rendu était « Session KLASSCI expirée. Veuillez vous reconnecter. » :
 * faux pour un compte qui n'a jamais eu de session KLASSCI (compte local, école
 * autonome), et la consigne enfermait dans une boucle. Quand la connexion
 * locale réussit, `LoginOrchestrator::refreshLinkedKlassciLogin` ne contacte
 * KLASSCI que si le compte porte un `klassci_id` : pour un compte local, se
 * reconnecter ne pose jamais de jeton.
 *
 * Le motif `klassci_token_missing` distingue ce cas de `klassci_session_expired`
 * (KLASSCI refuse un jeton présent,
 * {@see \App\Support\Klassci\KlassciClientErrorCatalog::reasonFor()}).
 * La réponse se construit ICI seulement, comme celle de
 * {@see KlassciUnavailableException::jsonResponse()} : chaque site la recopiait.
 *
 * Le message DOIT nommer KLASSCI : hors `/proxy/`, le front ne protège un 401
 * de la déconnexion qu'en lisant `/klassci/i` dans le message
 * (`frontend_lms` `errorHandler.js`, `shouldForceLogout`). Un test le fige.
 */
final class MissingKlassciTokenException extends RuntimeException
{
    public const CLIENT_MESSAGE = 'Aucun accès KLASSCI n\'est enregistré pour ce compte : '
        .'ces données, qui proviennent de KLASSCI, ne peuvent pas être affichées.';

    public const REASON = 'klassci_token_missing';

    /**
     * Corps de la réponse, pour les services qui rendent `{status, payload}`.
     *
     * @return array{success: false, message: string, reason: string}
     */
    public static function payload(): array
    {
        return [
            'success' => false,
            'message' => self::CLIENT_MESSAGE,
            'reason' => self::REASON,
        ];
    }

    /** Réponse HTTP canonique d'un jeton absent : 401, motif `klassci_token_missing`. */
    public static function jsonResponse(): JsonResponse
    {
        return response()->json(self::payload(), 401);
    }

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
