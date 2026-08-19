<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Disjoncteur des appels sortants KLASSCI, cloisonné par cible réseau (#578).
 *
 * ## Pourquoi partitionner (issue #578)
 *
 * Chaque institution possède sa propre instance KLASSCI
 * ({@see KlassciConfigResolver::baseUrl()}). Un état de disjoncteur GLOBAL
 * (clés littérales) rendait le mécanisme nuisible dès qu'il y a plus d'un
 * tenant : une panne de l'école A ouvrait le disjoncteur de TOUTES les écoles
 * (faux positif), et un succès de l'école B effaçait le compteur d'échecs de A
 * (faux négatif). L'état est donc désormais suffixé par l'empreinte de l'URL de
 * base résolue — cibles distinctes = états indépendants, même URL = état
 * partagé (co-hébergement légitime), pas d'URL = repli `default`.
 *
 * @see .claude/specs/578-circuit-breaker-per-tenant/design.md
 */
final class KlassciCircuitBreaker
{
    /** Préfixe commun ; le segment de partition est intercalé avant le suffixe. */
    private const KEY_PREFIX = 'klassci:circuit:';

    /** Jeton de partition de repli quand aucune cible n'est résolue (#578 R4). */
    private const DEFAULT_PARTITION = 'default';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly KlassciTargetResolver $target,
    ) {
    }

    public function isOpen(): bool
    {
        $openUntil = $this->intFromCache($this->openUntilKey());

        return $openUntil !== null && $openUntil > time();
    }

    public function secondsUntilRetry(): int
    {
        $openUntil = $this->intFromCache($this->openUntilKey());

        if ($openUntil === null) {
            return 0;
        }

        return max(0, $openUntil - time());
    }

    public function reportSuccess(): void
    {
        // #578 — ne réinitialise QUE la partition de la cible courante : un succès
        // sur le serveur B ne doit pas effacer les échecs accumulés du serveur A.
        $partition = $this->partition();
        $this->cache->forget($this->failuresKey($partition));
        $this->cache->forget($this->openUntilKey($partition));
    }

    public function reportFailure(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $partition = $this->partition();
        $failuresKey = $this->failuresKey($partition);

        $failures = ($this->intFromCache($failuresKey) ?? 0) + 1;
        $this->cache->put($failuresKey, $failures, $this->failureWindowSeconds());

        if ($failures >= $this->failureThreshold()) {
            $this->cache->put(
                $this->openUntilKey($partition),
                Carbon::now()->addSeconds($this->cooldownSeconds())->timestamp,
                $this->cooldownSeconds()
            );
        }
    }

    /**
     * Jeton de partition = empreinte de l'URL de base résolue, ou `default`.
     *
     * On hache l'URL (sha256) plutôt que de l'utiliser en clair : une base URL
     * peut porter un hôte qu'on ne veut pas voir apparaître dans les clés de
     * cache, et le hash garantit un fragment de clé au charset sûr. L'empreinte
     * porte sur la CHAÎNE EXACTE résolue, sans normalisation : direction
     * fail-safe (ne jamais fusionner deux cibles distinctes) ; deux institutions
     * au même `klassci_api_url` partagent donc naturellement la partition.
     */
    private function partition(): string
    {
        $baseUrl = $this->target->baseUrl();

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return self::DEFAULT_PARTITION;
        }

        return hash('sha256', $baseUrl);
    }

    private function failuresKey(?string $partition = null): string
    {
        return self::KEY_PREFIX . ($partition ?? $this->partition()) . ':failures';
    }

    private function openUntilKey(?string $partition = null): string
    {
        return self::KEY_PREFIX . ($partition ?? $this->partition()) . ':open_until';
    }

    private function enabled(): bool
    {
        return (bool) config('services.klassci.circuit_breaker_enabled', true);
    }

    private function failureThreshold(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_failures', 3);
    }

    private function cooldownSeconds(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_cooldown', 30);
    }

    private function failureWindowSeconds(): int
    {
        return self::positiveIntConfig('services.klassci.circuit_breaker_window', 60);
    }

    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function intFromCache(string $key): ?int
    {
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
