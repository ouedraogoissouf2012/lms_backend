<?php

declare(strict_types=1);

namespace App\Support\Shell;

use RuntimeException;
use Throwable;

/**
 * Raised by {@see ShellExecutor::run()} when a subprocess exits with a
 * non-zero code, times out, or otherwise fails.
 *
 * Design note — the exception **message** is intentionally generic (the
 * exit code only). Detailed information that an attacker could leverage,
 * such as the full command line, environment paths, or stderr content,
 * is stored on dedicated `readonly` properties and is meant to be read
 * by server-side log handlers only — never relayed to a remote client.
 * This satisfies `PRODUCTION_STANDARDS.md` §1.2 ("Aucun $e->getMessage()
 * exposé") and Requirement 5.2 of the refactor spec.
 *
 * Consumers (typically a converter service) should catch this exception,
 * log its rich data via `Log::error([...])`, and rethrow a higher-level
 * `\RuntimeException` with a user-safe message such as "Erreur de
 * conversion : opération échouée".
 *
 * @see \App\Support\Shell\ShellExecutor::run()
 */
final class ShellExecutionException extends RuntimeException
{
    /**
     * @param array<int, string> $command  The argv-style command that failed,
     *                                     captured as passed to Symfony Process.
     * @param string $stderr               Raw standard error output captured
     *                                     from the subprocess (server-side only).
     * @param int $exitCode                Process exit code ; 0 means timeout
     *                                     or signal kill rather than a normal
     *                                     non-zero exit.
     * @param Throwable|null $previous     Underlying Symfony Process exception
     *                                     (e.g. ProcessTimedOutException) to
     *                                     preserve the stack trace chain.
     */
    public function __construct(
        public readonly array $command,
        public readonly string $stderr,
        public readonly int $exitCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Shell execution failed (exit {$exitCode})",
            $exitCode,
            $previous,
        );
    }
}
