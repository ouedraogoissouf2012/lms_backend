<?php

declare(strict_types=1);

namespace Tests\Support\SondeMemoire;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * #1356 — SONDE DE MESURE, jamais fusionnée : où la suite retient-elle sa mémoire ?
 *
 * Écrit en fin de suite, sur la sortie d'erreur, le rapport de {@see ReleveMemoire}.
 */
final class SondeMemoireExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $releve = new ReleveMemoire;

        $facade->registerSubscribers(
            new class($releve) implements ExecutionStartedSubscriber
            {
                public function __construct(private readonly ReleveMemoire $releve) {}

                public function notify(ExecutionStarted $event): void
                {
                    $this->releve->depart();
                }
            },
            new class($releve) implements PreparedSubscriber
            {
                public function __construct(private readonly ReleveMemoire $releve) {}

                public function notify(Prepared $event): void
                {
                    $this->releve->testPret();
                }
            },
            new class($releve) implements FinishedSubscriber
            {
                public function __construct(private readonly ReleveMemoire $releve) {}

                public function notify(Finished $event): void
                {
                    $this->releve->testFini($event->test()->id());
                }
            },
            new class($releve) implements ExecutionFinishedSubscriber
            {
                public function __construct(private readonly ReleveMemoire $releve) {}

                public function notify(ExecutionFinished $event): void
                {
                    fwrite(STDERR, $this->releve->rapport());
                }
            },
        );
    }
}
