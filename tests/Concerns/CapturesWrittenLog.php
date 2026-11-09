<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Le journal tel qu'il est ÉCRIT, et non tel que la façade le reçoit (#948).
 *
 * Un `TestHandler` branché sur le canal par défaut voit ce qu'écrivent le
 * rapporteur de Laravel (`Handler::reportThrowable`) et chaque `LoggerInterface`
 * injecté. Chaque enregistrement est ensuite formaté par le formateur réel des
 * canaux fichier (`LogManager::formatter()`, traces comprises) : c'est cette
 * chaîne qui finit dans `laravel.log`. Une trace y est écrite par
 * `getTraceAsString()`, avec les arguments des fonctions, sauf si
 * `zend.exception_ignore_args` est actif.
 */
trait CapturesWrittenLog
{
    /** Un argument de type chaîne apparaît dans une trace tronqué à cette longueur. */
    private const TRACE_ARGUMENT_LENGTH = 15;

    private TestHandler $journal;

    protected function captureWrittenLog(): void
    {
        $this->journal = new TestHandler;
        Log::getLogger()->pushHandler($this->journal);
    }

    /** @return list<LogRecord> */
    protected function recordsAtLeast(Level $threshold): array
    {
        return array_values(array_filter(
            $this->journal->getRecords(),
            static fn (LogRecord $record): bool => $record->level->value >= $threshold->value,
        ));
    }

    /**
     * Aucun témoin n'est écrit, ni entier, ni par le préfixe qu'en garderait une
     * trace (`'jeton-enseignan...'`).
     */
    protected function assertNoWitnessWritten(string ...$witnesses): void
    {
        self::assertNotSame([], $this->journal->getRecords(), 'Rien n\'a été écrit : l\'absence ne prouverait rien.');

        $formatter = new LineFormatter(null, null, true, true, true);
        foreach ($this->journal->getRecords() as $record) {
            $written = $formatter->format($record);
            foreach ($witnesses as $witness) {
                self::assertStringNotContainsString($witness, $written);
                self::assertStringNotContainsString(substr($witness, 0, self::TRACE_ARGUMENT_LENGTH), $written);
            }
        }
    }
}
