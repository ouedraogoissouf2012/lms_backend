<?php

declare(strict_types=1);

namespace App\Services\Chapter;

/**
 * Compte rendu d'un passage de `chapters:purge` (#674).
 *
 * Les compteurs sont séparés parce qu'ils ne disent pas la même chose en cas
 * d'incident : `ignored` est un fonctionnement nominal (le chapitre appartient
 * à la rétention visio, ou n'est plus éligible depuis la lecture du lot), tandis
 * que `failed` est un échec réel — et c'est lui seul qui décide du code de
 * sortie de la commande.
 */
final class ChapterRetentionResult
{
    /** Chapitres à la corbeille au-delà de l'échéance ET purgeables par nous. */
    public int $eligible = 0;

    /** Chapitres réellement détruits, lignes et fichiers. */
    public int $purged = 0;

    /** Écartés délibérément : propriété de la rétention visio, ou plus éligibles. */
    public int $ignored = 0;

    /** Échecs — le seul compteur qui fasse échouer la commande. */
    public int $failed = 0;

    /**
     * Inventaire du parc, indépendant de l'échéance — renseigné par
     * {@see ChapterRetentionService::fillInventory()}, qui documente pourquoi.
     */
    public int $trashedTotal = 0;

    public ?string $oldestTrashedAt = null;
}
