<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Historique de recherche per-user — split-19/search.
 *
 * Extrait de `SearchController::searchHistory` et `saveSearchHistory` :
 * persistance des 10 dernières requêtes d'un utilisateur dans le cache
 * pendant 30 jours.
 *
 * ## DI strict (§1.6 D)
 *
 * `Cache::get` / `Cache::put` remplacés par `CacheRepository` injecté.
 *
 * @see app/Http/Controllers/API/SearchController.php
 */
final class SearchHistoryService
{
    /**
     * TTL du cache de l'historique (30 jours, en secondes).
     */
    private const HISTORY_TTL_SECONDS = 30 * 24 * 60 * 60;

    /**
     * Nombre d'entrées conservées dans l'historique.
     */
    private const HISTORY_MAX_ENTRIES = 10;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * Retourner l'historique de recherche de l'utilisateur.
     *
     * @return array<int, array{query: string, timestamp: string}>
     */
    public function getHistory(User $user): array
    {
        /** @var array<int, array{query: string, timestamp: string}> $history */
        $history = $this->cache->get($this->cacheKey($user), []);

        return $history;
    }

    /**
     * Sauvegarder une nouvelle requête en tête de l'historique
     * (tronqué à {@see self::HISTORY_MAX_ENTRIES} entrées).
     */
    public function save(User $user, string $query): void
    {
        $history = $this->getHistory($user);

        array_unshift($history, [
            'query' => $query,
            'timestamp' => now()->toIso8601String(),
        ]);

        $history = array_slice($history, 0, self::HISTORY_MAX_ENTRIES);

        $this->cache->put($this->cacheKey($user), $history, self::HISTORY_TTL_SECONDS);
    }

    private function cacheKey(User $user): string
    {
        return "search_history_user_{$user->id}";
    }
}
