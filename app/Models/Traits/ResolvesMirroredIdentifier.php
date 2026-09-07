<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Contracts\MirroredFromKlassci;
use Illuminate\Database\Eloquent\Builder;

/**
 * Implémentation UNIQUE de {@see MirroredFromKlassci} :
 * traduire un identifiant — local ou KLASSCI — vers l'identifiant LOCAL, borné
 * à une institution.
 *
 * ## Le défaut de classe que ce trait supprime
 *
 * La même question était posée à QUATRE endroits, selon DEUX sémantiques :
 *
 * | Site d'origine | Forme | Sémantique |
 * |---|---|---|
 * | `StoreLessonRequest::authorize()` | `where(id)->orWhere(klassci_id)` | ambiguë |
 * | `StoreLessonRequest::rules()` | idem, dans une closure | ambiguë |
 * | `LessonCrudOperationsService` (matière) | deux requêtes successives | local prioritaire |
 * | `LessonCrudOperationsService` (classe) | deux requêtes successives | local prioritaire |
 *
 * Quatre copies d'une règle, c'est quatre occasions de diverger — et la
 * divergence s'est produite : `matiere_id` n'acceptait plus que l'espace
 * local, si bien que toute création de leçon portant une matière échouait en
 * 422. Le diagnostic a coûté une soirée, parce que le symptôme ne désignait
 * jamais la cause.
 *
 * ## Deux invariants, non négociables
 *
 * 1. **Précédence à l'espace LOCAL.** Rien n'interdit que l'id local d'une
 *    ligne égale le `klassci_id` d'une autre : ce sont deux numérotations
 *    indépendantes. Avec un `orWhere`, la ligne renvoyée dépendait de l'ordre
 *    du moteur — donc du hasard, et différemment sous SQLite et sous MySQL.
 * 2. **Bornage à l'institution, toujours.** Le `klassci_id` n'est unique QUE
 *    par institution (#707) : deux établissements portent légitimement la
 *    matière 3. Résoudre sans borner franchirait la frontière du tenant. Le
 *    scope global {@see BelongsToInstitution} s'ajoute à ce filtre, jamais ne
 *    le remplace : il est *fail-open* quand aucun tenant n'est résolu, ce
 *    filtre-ci ne l'est pas.
 *
 * Vérifié par tests/Unit/Models/Traits/ResolvesMirroredIdentifierTest.php
 * et tests/Feature/Lesson/KlassciIdentifierDualityTest.php.
 */
trait ResolvesMirroredIdentifier
{
    /**
     * Traduire plutôt que recopier est ce qui garde nos colonnes homogènes :
     * ranger un `klassci_id` dans une clé étrangère locale mélangerait deux
     * espaces dans une même colonne — la faute exacte qui a produit la fuite
     * entre enseignants de #707.
     */
    public static function localIdFor(mixed $identifiant, mixed $institutionId): ?int
    {
        if (! is_numeric($identifiant)) {
            return null;
        }

        $recherche = (int) $identifiant;

        // Espace local d'abord : voir l'invariant 1 du docblock du trait.
        $local = self::mirroredScope($institutionId)->where('id', $recherche)->value('id');

        if (is_numeric($local)) {
            return (int) $local;
        }

        $miroir = self::mirroredScope($institutionId)->where('klassci_id', $recherche)->value('id');

        return is_numeric($miroir) ? (int) $miroir : null;
    }

    /**
     * Volontairement dérivée de {@see self::localIdFor()} et non requêtée à
     * part : c'est ce qui interdit qu'une requête soit acceptée à la validation
     * puis échoue à la résolution — les deux réponses ne PEUVENT plus diverger.
     */
    public static function existsFor(mixed $identifiant, mixed $institutionId): bool
    {
        return static::localIdFor($identifiant, $institutionId) !== null;
    }

    /**
     * La traduction STRICTE d'un identifiant dont on SAIT qu'il vient de
     * KLASSCI — un payload, jamais un client.
     *
     * ## Pourquoi elle est distincte de `localIdFor()`
     *
     * `localIdFor()` répond à « je ne sais pas dans quel espace parle
     * l'appelant » et tranche alors en faveur du local. C'est le bon arbitrage
     * pour une requête entrante ; c'en est un MAUVAIS pour une donnée dont
     * l'espace est connu : sur une collision, elle rendrait la ligne de l'autre
     * espace, silencieusement.
     *
     * Deviner quand on sait, c'est réintroduire le hasard qu'on vient de
     * supprimer. Quand l'espace est connu, on le nomme.
     */
    public static function localIdForKlassciId(mixed $klassciId, mixed $institutionId): ?int
    {
        if (! is_numeric($klassciId)) {
            return null;
        }

        $local = self::mirroredScope($institutionId)->where('klassci_id', (int) $klassciId)->value('id');

        return is_numeric($local) ? (int) $local : null;
    }

    /**
     * Les attributs Eloquent n'étant pas typés dans ce dépôt,
     * `$user->institution_id` arrive en `mixed` : la normalisation est assumée
     * ICI, une fois, plutôt que recopiée par chaque appelant — soit exactement
     * la duplication que ce trait supprime. `null` ne résout que les lignes
     * sans institution, le cas d'un compte hors établissement.
     *
     * Elle est appelée en `self::`, jamais `static::` : invoquer une méthode
     * PRIVÉE par liaison statique tardive n'est pas sûr — une classe fille n'y
     * aurait pas accès. PHPStan le signale, mais seulement en CI, plus stricte
     * que l'analyse locale. La liaison tardive reste là où elle a un sens :
     * `static::query()`, juste en dessous, qui DOIT résoudre le modèle utilisant
     * le trait.
     *
     * @return Builder<static>
     */
    private static function mirroredScope(mixed $institutionId): Builder
    {
        return static::query()->where(
            'institution_id',
            is_numeric($institutionId) ? (int) $institutionId : null,
        );
    }
}
