<?php

declare(strict_types=1);

namespace App\Support\Klassci;

/**
 * Source UNIQUE de la classification des erreurs 4xx renvoyées par KLASSCI.
 *
 * Elle n'existait qu'en un exemplaire privé, dans
 * {@see \App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors} — dette
 * explicitement tracée dans son docblock. Le trait proxy
 * ({@see \App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors}), lui,
 * ne traitait QUE le 401 et écrasait tout le reste en 500 : un refus d'autorisation
 * KLASSCI (403) se présentait au client comme une panne du serveur LMS, rendant un
 * problème de droits indiscernable d'une indisponibilité — pour l'utilisateur comme
 * pour la supervision.
 *
 * Seule la CLASSIFICATION est factorisée ici. Chaque trait garde son enveloppe : le
 * proxy écrit sa réponse à la main et porte une clé `reason` que ses clients
 * consomment ; les contrôleurs LMS passent par `errorResponse()`. Les fusionner
 * changerait l'un des deux contrats client.
 *
 * Aucun message ne dérive du corps KLASSCI ni de `$e->getMessage()` (§1.2) — ce
 * dernier vaut « Erreur API KLASSCI: 403 » et n'a rien à faire dans une réponse.
 *
 * @see \App\Services\Klassci\KlassciHttpClient lève RuntimeException($status) sur 4xx
 */
final class KlassciClientErrorCatalog
{
    /** Bornes de la plage HTTP « erreur du client » relayable telle quelle. */
    private const CLIENT_ERROR_MIN = 400;

    private const CLIENT_ERROR_MAX = 499;

    /**
     * Le statut est-il un code d'erreur client relayable au demandeur ?
     *
     * La garde de TYPE est porteuse, pas décorative : le code d'une exception PHP
     * n'est pas garanti entier. `PDOException` — donc `Illuminate\Database\QueryException`,
     * elle aussi une `RuntimeException` — porte un SQLSTATE en chaîne. En PHP 8,
     * `'42S02' >= 400 && '42S02' <= 499` vaut `true` : sans `is_int`, une chaîne
     * partirait comme code HTTP à `response()->json()` et la `TypeError` naîtrait
     * DEPUIS le gestionnaire d'erreurs lui-même.
     *
     * Les 5xx KLASSCI n'arrivent jamais ici : le client HTTP les convertit en
     * {@see \App\Exceptions\KlassciUnavailableException} (503 + `Retry-After`).
     */
    public static function isRelayableClientStatus(mixed $status): bool
    {
        return is_int($status)
            && $status >= self::CLIENT_ERROR_MIN
            && $status <= self::CLIENT_ERROR_MAX;
    }

    /**
     * Message orienté action, jamais le corps brut renvoyé par KLASSCI (§1.2).
     *
     * 401 et 403 partagent leur message (même cause vécue : ce compte n'a pas
     * accès), mais gardent des STATUTS distincts. 404 conserve son propre message :
     * les identifiants de classe sont déjà énumérables via `/proxy/classes`, donc
     * fusionner 403 et 404 ne protégerait rien et coûterait la précision au client.
     */
    public static function messageFor(int $status): string
    {
        return match ($status) {
            401, 403 => 'Accès refusé par KLASSCI pour ce compte.',
            404 => 'Aucune donnée KLASSCI pour ce compte. '
                .'Vérifiez que le profil correspondant (enseignant, étudiant) y existe bien.',
            409 => 'Conflit signalé par KLASSCI sur cette ressource.',
            422 => 'KLASSCI a refusé la requête : données invalides.',
            429 => 'KLASSCI limite temporairement les requêtes. Veuillez réessayer dans quelques instants.',
            default => 'KLASSCI a refusé la requête.',
        };
    }
}
