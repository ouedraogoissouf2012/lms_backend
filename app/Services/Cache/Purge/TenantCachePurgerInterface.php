<?php

declare(strict_types=1);

namespace App\Services\Cache\Purge;

/**
 * Stratégie de purge PHYSIQUE des entrées cache d'un tenant (#547).
 *
 * Abstraction dédiée (ISP §1.6 I : un seul verbe) qui isole le SEUL axe qui
 * varie selon le store cache actif — comment libérer réellement l'espace :
 *   - Redis/Memcached → flush par tag ;
 *   - database        → DELETE ciblé par motif de clé (namespace tenant) ;
 *   - file/array      → no-op journalisé (pas de purge ciblée native).
 *
 * `TenantScopedCache` dépend de cette interface, jamais des `if store`
 * (§1.6 D + O : ajouter un store = ajouter une implémentation, pas éditer
 * l'existant). Chaque implémentation est substituable par un fake en test
 * sans contournement (§1.6 L).
 */
interface TenantCachePurgerInterface
{
    /**
     * Purge physiquement les entrées du namespace tenant fourni.
     *
     * @param  string  $namespace  Namespace serveur (`institution_{id}` |
     *                             `institution_none`), jamais un input client.
     */
    public function purge(string $namespace): void;
}
