<?php

declare(strict_types=1);

namespace App\Console\Commands\Scheduler;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class QueueDrainCommand extends Command
{
    public const HEARTBEAT_KEY = 'queue:worker:last_heartbeat_at';

    /**
     * Marge sous le `memory_limit` PHP de production (256 Mo, cf. docker/uploads.ini).
     *
     * `queue:work` tourne dans le MÊME processus que cette commande, pas dans un
     * processus neuf : la mémoire déjà consommée par le boot de Laravel compte dans
     * son budget. Le défaut Laravel de 128 Mo est calibré pour un démon lancé seul —
     * ici il est trop bas, et le worker sort immédiatement avec le code 12
     * (`Worker::EXIT_MEMORY_LIMIT`) sans avoir traité le moindre job.
     *
     * C'est ce qui rendait `QueueDrainCommandTest` intermittent : sous PHPUnit le
     * processus dépasse déjà 180 Mo, donc le worker franchissait le seuil dès son
     * démarrage. Le test échouait ou passait selon les tests exécutés avant lui.
     */
    private const DEFAULT_MEMORY_MB = 192;

    /** Borne le drain sous le tick d'une minute du scheduler. */
    private const DEFAULT_MAX_TIME_S = 55;

    protected $signature = 'queue:drain
        {--memory= : Limite mémoire du worker en Mo (défaut : 192)}
        {--max-time= : Durée maximale du drain en secondes (défaut : 55)}';

    protected $description = 'Draine les queues puis enregistre le heartbeat du worker';

    public function handle(CacheRepository $cache): int
    {
        $exitCode = $this->call('queue:work', [
            '--queue'           => 'high,default,low',
            '--stop-when-empty' => true,
            '--max-time'        => $this->resolveIntOption('max-time', self::DEFAULT_MAX_TIME_S),
            '--memory'          => $this->resolveIntOption('memory', self::DEFAULT_MEMORY_MB),
        ]);

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $cache->forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }

    /**
     * Valeur entière d'une option, ou son défaut si absente ou invalide.
     *
     * Les deux réglages sont surchargeables pour la même raison : un test doit
     * pouvoir donner au worker un budget mémoire supérieur à ce que PHPUnit a déjà
     * consommé, et une durée courte — sinon il mesure la suite plutôt que la
     * commande, et immobilise la CI 55 secondes pour une queue vide.
     */
    private function resolveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (is_string($value) && is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $default;
    }
}
