<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync\Cursor;

/**
 * Persistance de la position de reprise de la sync des séances (#582).
 *
 * Abstraction volontaire (§1.6-D) : le service de sync dépend du contrat, jamais
 * d'Eloquent. Le nom de la ligne persistée n'apparaît pas dans le contrat — il
 * est un détail de l'implémentation, pas une décision de l'appelant.
 */
interface SeanceSyncCursorStore
{
    /**
     * Position persistée, ou une position neuve (début de cycle) si aucune
     * n'existe. Ne persiste rien : la persistance est explicite via `save()`.
     */
    public function load(): SeanceSyncPosition;

    /** Enregistre la position atteinte à la fin d'une passe tronquée. */
    public function save(SeanceSyncPosition $position): void;

    /**
     * Clôt le cycle : la passe suivante repartira du premier enseignant, avec un
     * nouveau `cycleStartedAt` et aucun tenant souillé.
     */
    public function reset(): void;
}
