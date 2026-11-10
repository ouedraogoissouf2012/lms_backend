<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Le journal d'une exception ATTRAPÉE : le message du site, son contexte
 * métier, puis la description de l'exception (`ExceptionDescription`).
 *
 * Un site qui attrape une exception pour continuer (repli, valeur par défaut,
 * réponse générique) garde ainsi son niveau et ses identifiants métier, et
 * l'exception est décrite partout de la même façon. Aucune exception n'est
 * passée au contexte : la description est faite de champs scalaires.
 *
 * - `error` : une panne. La trace de tête est jointe (sans arguments,
 *   `zend.exception_ignore_args`).
 * - `warning` : une dégradation attendue. Sans trace.
 * - `info` et `debug` : un repli prévu, journalisé à ce niveau par son site.
 *   Sans trace.
 *
 * Le contexte du site passe en premier : une clé de la description ne
 * l'écrase pas.
 */
final class CaughtExceptionLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExceptionDescription $description,
    ) {}

    /** @param  array<string, mixed>  $context */
    public function error(string $message, Throwable $e, array $context = []): void
    {
        $this->logger->error($message, $context + $this->description->describe($e) + [
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function warning(string $message, Throwable $e, array $context = []): void
    {
        $this->logger->warning($message, $context + $this->description->describe($e));
    }

    /** @param  array<string, mixed>  $context */
    public function info(string $message, Throwable $e, array $context = []): void
    {
        $this->logger->info($message, $context + $this->description->describe($e));
    }

    /** @param  array<string, mixed>  $context */
    public function debug(string $message, Throwable $e, array $context = []): void
    {
        $this->logger->debug($message, $context + $this->description->describe($e));
    }
}
