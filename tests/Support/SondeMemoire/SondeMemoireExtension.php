<?php

declare(strict_types=1);

namespace Tests\Support\SondeMemoire;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
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
 * Écrit en fin de suite, sur la sortie d'erreur :
 * - la mémoire au départ de l'exécution, quand les `DataProvider` sont déjà construits ;
 * - la courbe de `memory_get_usage()` tous les 250 tests ;
 * - les 30 classes de test qui laissent le plus de mémoire RETENUE : usage après la
 *   classe moins usage avant, chaque fois après `gc_collect_cycles()`, pour ne compter
 *   que ce qui survit, pas les déchets en attente de collecte.
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
            new class($releve) implements FinishedSubscriber
            {
                public function __construct(private readonly ReleveMemoire $releve) {}

                public function notify(Finished $event): void
                {
                    $test = $event->test();
                    $this->releve->testFini($test instanceof TestMethod ? $test->className() : $test->id());
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
