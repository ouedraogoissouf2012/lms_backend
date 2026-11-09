<?php

declare(strict_types=1);

namespace App\Support\Logging;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * Le stockage des jobs échoués, qui y écrit l'exception par sa description.
 *
 * Décore le fournisseur configuré (`queue.failed.driver`) sur le patron de
 * `Illuminate\Foundation\Cloud\FailedJobProvider` : tout est délégué, sauf
 * `log()`, qui lui passe une `DescribedFailure`. Le `payload` n'est pas
 * touché : `queue:retry` relance le job comme avant.
 *
 * Lié par `QueueFailureServiceProvider` (`extend('queue.failer')`).
 */
final class DescribedFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly ExceptionDescription $description,
    ) {}

    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     * @return string|int|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        return $this->failer->log($connection, $queue, $payload, new DescribedFailure($exception, $this->description));
    }

    /**
     * @param  string|null  $queue
     * @return array<int, mixed>
     */
    public function ids($queue = null)
    {
        return $this->failer->ids($queue);
    }

    /** @return array<int, mixed> */
    public function all()
    {
        return $this->failer->all();
    }

    /**
     * @param  mixed  $id
     * @return object|null
     */
    public function find($id)
    {
        return $this->failer->find($id);
    }

    /**
     * @param  mixed  $id
     * @return bool
     */
    public function forget($id)
    {
        return $this->failer->forget($id);
    }

    /**
     * @param  int|null  $hours
     * @return void
     */
    public function flush($hours = null)
    {
        $this->failer->flush($hours);
    }

    /**
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @return int
     */
    public function count($connection = null, $queue = null)
    {
        return $this->failer instanceof CountableFailedJobProvider ? $this->failer->count($connection, $queue) : 0;
    }

    /** @return int */
    public function prune(DateTimeInterface $before)
    {
        return $this->failer instanceof PrunableFailedJobProvider ? $this->failer->prune($before) : 0;
    }
}
