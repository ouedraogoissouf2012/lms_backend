<?php

declare(strict_types=1);

namespace App\Support\Shell;

/**
 * Immutable result of a successful shell command execution via ShellExecutor.
 *
 * Captures the three pieces of information that any caller of an external
 * process needs to consume the outcome :
 * - stdout : the standard output as a single string (lines preserved).
 * - stderr : the standard error output ; usually empty on success but kept
 *   because some tools (LibreOffice, Ghostscript) write diagnostics to stderr
 *   even when they succeed.
 * - exitCode : the process exit code ; always 0 here since a non-zero exit
 *   would have triggered a {@see ShellExecutionException} instead.
 *
 * The class is intentionally minimal : it is a pure data carrier with no
 * behaviour. Mutability would invite stale data ; readonly properties make
 * it safe to pass around the result without defensive copies.
 *
 * @see \Symfony\Component\Process\Process::getOutput()
 * @see \Symfony\Component\Process\Process::getErrorOutput()
 * @see \Symfony\Component\Process\Process::getExitCode()
 */
final class ShellResult
{
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $exitCode,
    ) {
    }
}
