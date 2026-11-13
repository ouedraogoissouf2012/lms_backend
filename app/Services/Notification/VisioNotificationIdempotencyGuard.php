<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Un avertissement visio n'est envoyé qu'une fois par séance et par type, sur 24 h.
 *
 * ## La clé : l'id LOCAL de la séance (V1)
 *
 * La route reçoit un identifiant KLASSCI dans une école KLASSCI et un id local dans
 * une école en mode autonome : le même nombre y désigne deux séances différentes,
 * entre écoles comme dans une seule école KLASSCI. La garde ne lit pas
 * l'établissement (le job tourne sans tenant). Elle se clé donc sur `seances.id`,
 * que chaque source déclare (`seance_locale_id`) et que la notification porte.
 *
 * Un job mis en file avant ce changement n'a pas cet id : il garde l'ancienne clé,
 * l'identifiant de la route (repli déclaré, le temps que la file se vide).
 */
final class VisioNotificationIdempotencyGuard
{
    private const LOCK_TTL_SECONDS = 150;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param Closure(): int $dispatch */
    public function run(string $type, int $seanceId, Closure $dispatch, ?int $seanceLocaleId = null): int
    {
        $store = $this->cache->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support atomic locks.');
        }

        $result = $store->lock(self::key($type, $seanceId, $seanceLocaleId), self::LOCK_TTL_SECONDS)
            ->get(function () use ($type, $seanceId, $dispatch, $seanceLocaleId): int {
                return $this->database->transaction(function () use ($type, $seanceId, $dispatch, $seanceLocaleId): int {
                    if ($this->alreadyDispatched($type, $seanceId, $seanceLocaleId)) {
                        $this->logger->info('Async visio notification skipped', [
                            'type' => $type,
                            'seance_id' => $seanceId,
                            'seance_locale_id' => $seanceLocaleId,
                            'reason' => 'already_dispatched',
                        ]);

                        return 0;
                    }

                    return $dispatch();
                });
            });

        if ($result === false) {
            throw new RuntimeException('Visio notification dispatch is already locked.');
        }

        if (! is_int($result)) {
            throw new RuntimeException('Visio notification dispatch returned an invalid result.');
        }

        return $result;
    }

    public static function key(string $type, int $seanceId, ?int $seanceLocaleId = null): string
    {
        return $seanceLocaleId === null
            ? "visio-notification:{$type}:seance:{$seanceId}"
            : "visio-notification:{$type}:seance-locale:{$seanceLocaleId}";
    }

    private function alreadyDispatched(string $type, int $seanceId, ?int $seanceLocaleId): bool
    {
        $notifications = Notification::query()
            ->where('type', $type)
            ->where('created_at', '>', now()->subDay());

        // Les deux chemins JSON sont écrits en clair : aucun fragment variable dans la requête.
        return ($seanceLocaleId === null
            ? $notifications->whereRaw("json_extract(data, '$.seance_id') = ?", [$seanceId])
            : $notifications->whereRaw("json_extract(data, '$.seance_locale_id') = ?", [$seanceLocaleId])
        )->exists();
    }
}
