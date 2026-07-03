<?php

declare(strict_types=1);

namespace App\Services\Scheduler;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Marqueur de vie du scheduler (issue #369).
 *
 * Une tâche planifiée chaque minute appelle {@see beat()} ; la commande
 * `scheduler:healthcheck` appelle {@see isAlive()} pour détecter un cron
 * mort (cPanel mutualisé sans Supervisor ni monitoring intégré).
 *
 * Le marqueur vit dans le cache applicatif (store `database` en prod) :
 * c'est le seul canal partagé entre le process cron et un process de
 * monitoring, sans dépendre du filesystem ni d'une table dédiée.
 *
 * @see PRODUCTION_STANDARDS.md §1.6 S — une seule responsabilité : le battement
 * @see PRODUCTION_STANDARDS.md §1.6 D — Repository injecté, pas de Facade
 */
final class SchedulerHeartbeat
{
    /** Clé cache système (hors périmètre tenant — le scheduler est global). */
    public const CACHE_KEY = 'scheduler:last_heartbeat_at';

    /**
     * Seuil de péremption en minutes. Le heartbeat bat chaque minute :
     * 5 battements manqués = scheduler mort. Garantit une détection
     * en < 10 min (critère d'acceptation issue #369).
     */
    public const STALE_AFTER_MINUTES = 5;

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * Enregistre un battement. Stocké sans TTL : un marqueur expiré serait
     * indistinguable d'un scheduler jamais démarré, on préfère garder la
     * date exacte du dernier battement pour le log de diagnostic.
     */
    public function beat(): void
    {
        $this->cache->put(self::CACHE_KEY, CarbonImmutable::now()->toIso8601String());
    }

    /**
     * Date du dernier battement, ou null si absent/corrompu.
     */
    public function lastBeatAt(): ?CarbonImmutable
    {
        $raw = $this->cache->get(self::CACHE_KEY);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            // Marqueur corrompu = scheduler considéré mort : le healthcheck
            // doit alerter plutôt que de crasher.
            return null;
        }
    }

    /**
     * Le scheduler est vivant si le dernier battement date de STRICTEMENT
     * moins de {@see STALE_AFTER_MINUTES} minutes (contrat issue #369 :
     * « dernière exécution < 5 min → exit 0 »).
     */
    public function isAlive(): bool
    {
        $lastBeat = $this->lastBeatAt();

        return $lastBeat !== null
            && $lastBeat->greaterThan(CarbonImmutable::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
