<?php

declare(strict_types=1);

namespace App\Services\Klassci;

/**
 * PERF-02 (issue #137) — Memoization intra-request pour les appels KLASSCI.
 *
 * Tableau privé en mémoire, indexé par hash `xxh3` non-cryptographique de
 * `(method, endpoint, params, tokenHash)`. Vidé sur tout write (POST/PUT/DELETE)
 * pour garantir qu'on ne sert pas de stale data dans la même requête HTTP serveur
 * après une mutation.
 *
 * ## Pourquoi un collaborateur séparé
 *
 * Avant l'éclatement, ces 3 méthodes vivaient dans `KlassciProxyService` :
 * - Smell SRP : la classe mélangeait HTTP + cache + memoization + endpoints métier
 *   (cf. audit `spec-architect` MED-2 sur la PR initiale).
 * - Smell testabilité : `Mockery::mock(KlassciProxyService::class)` masquait toute
 *   la mécanique memo en bloc, ne permettait pas de tester finement les invariants.
 *
 * En extrayant cette responsabilité, on obtient :
 * - SRP respecté : la classe a UNE raison de changer (politique memoization).
 * - DI claire : `Mockery::mock(KlassciRequestMemo::class)` pour les tests d'autres
 *   services qui composent avec cette couche.
 * - Borne mémoire facile à ajouter ultérieurement (FIFO/LRU) au seul endroit utile.
 *
 * ## Sécurité — pas de fuite token
 *
 * {@see self::userTokenHash()} retourne `xxh3(token)[0:16]` (64 bits effectifs).
 * Non-cryptographique mais suffisant pour un INDEX : on ne cherche pas un secret,
 * on cherche une fonction de hachage rapide avec faible probabilité de collision.
 * Le token brut n'est jamais persisté ni loggé.
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §2-3
 */
final class KlassciRequestMemo
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    private bool $enabled;

    public function __construct(?bool $enabled = null)
    {
        // Lecture de config au constructeur — pas re-lu à chaque appel pour rester
        // déterministe sur la durée d'une requête (un toggle live serait surprenant).
        $this->enabled = $enabled ?? (bool) config('services.klassci.memoize_enabled', true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Calcule la clé memo pour `(method, endpoint, params, tokenHash)`.
     *
     * Le `tokenHash` est `null` pour les méthodes globales (token système) et
     * non-null pour `requestWithUserToken()` — distingue les 2 modes sans collision.
     *
     * @param  array<string, mixed>  $params
     */
    public function memoKey(string $method, string $endpoint, array $params, ?string $tokenHash): string
    {
        return hash('xxh3', (string) json_encode([$method, $endpoint, $params, $tokenHash]));
    }

    /**
     * Hash opaque du user-token pour usage comme INDEX (jamais comme secret).
     *
     * `xxh3` est non-cryptographique mais plus rapide que `md5` (voir benchmarks
     * officiels xxHash : https://github.com/Cyan4973/xxHash#benchmarks). Tronqué
     * à 16 chars (64 bits effectifs) → collision quasi-impossible sur ~200k tokens.
     */
    public function userTokenHash(string $userToken): string
    {
        return substr(hash('xxh3', $userToken), 0, 16);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $memoKey): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->memo[$memoKey] ?? null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $memoKey, array $value): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->memo[$memoKey] = $value;
    }

    public function has(string $memoKey): bool
    {
        return $this->enabled && isset($this->memo[$memoKey]);
    }

    /**
     * Vide entièrement le memo. Appelée après toute opération write qui invalide
     * le cache distribué — reset complet plutôt que ciblé (un POST peut invalider
     * plusieurs endpoints liés ; une carte endpoint→dépendances serait overkill).
     */
    public function clear(): void
    {
        $this->memo = [];
    }

    /**
     * Taille actuelle du memo. Utilisé en tests + observabilité ops.
     */
    public function size(): int
    {
        return count($this->memo);
    }
}
