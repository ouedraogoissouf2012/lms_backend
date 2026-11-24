<?php

declare(strict_types=1);

namespace Tests\Support\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Journal d'essai partagé : garde chaque entrée, message et contexte, pour qu'un test
 * prouve qu'un secret n'y est jamais entré (#1332, #1213).
 */
final class JournalRelu extends AbstractLogger
{
    /** @var list<string> */
    public array $lignes = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->lignes[] = $message.' '.json_encode($context);
    }
}
