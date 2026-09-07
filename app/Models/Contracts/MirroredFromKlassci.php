<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Models\Traits\ResolvesMirroredIdentifier;

/**
 * Modèle miroité depuis KLASSCI : il porte un `klassci_id` en plus de sa clé
 * primaire locale, et vit dans DEUX espaces de numérotation indépendants.
 *
 * Déclarer ce contrat, c'est déclarer qu'un identifiant reçu du frontend peut
 * appartenir à l'un ou l'autre espace — et que la traduction vers l'espace
 * LOCAL passe par {@see ResolvesMirroredIdentifier}, jamais
 * par une requête recopiée.
 *
 * ## Ouvert à l'extension
 *
 * Rendre une entité duale tient en deux lignes sur son modèle :
 *
 * ```php
 * class Evaluation extends Model implements MirroredFromKlassci
 * {
 *     use ResolvesMirroredIdentifier;
 * }
 * ```
 *
 * Elle hérite alors de la précédence à l'espace local et du bornage tenant,
 * sans qu'une seule ligne existante ne soit modifiée.
 */
interface MirroredFromKlassci
{
    /**
     * L'identifiant LOCAL désigné, ou `null` si rien ne correspond dans cette
     * institution — que l'entrée soit un id local ou un `klassci_id`.
     */
    public static function localIdFor(mixed $identifiant, mixed $institutionId): ?int;

    /**
     * La même question, en booléen : posée par la validation et par
     * l'autorisation.
     */
    public static function existsFor(mixed $identifiant, mixed $institutionId): bool;

    /**
     * La traduction STRICTE d'un identifiant venu d'un payload KLASSCI, dont
     * l'espace est donc connu.
     *
     * Deux méthodes plutôt qu'un drapeau, parce que ce sont deux questions
     * différentes : {@see self::localIdFor()} arbitre une ambiguïté,
     * celle-ci n'en a aucune à arbitrer. Deviner quand on sait, c'est
     * réintroduire le hasard qu'on vient de supprimer.
     */
    public static function localIdForKlassciId(mixed $klassciId, mixed $institutionId): ?int;
}
