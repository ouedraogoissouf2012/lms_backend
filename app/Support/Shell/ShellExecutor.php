<?php

declare(strict_types=1);

namespace App\Support\Shell;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as SymfonyProcessException;
use Symfony\Component\Process\Process;

/**
 * Sole entry point for executing external processes within `app/`.
 *
 * Wraps `Symfony\Component\Process\Process` to provide :
 * - **Injection-safe execution** : commands are passed as `array<int,string>`,
 *   never as concatenated shell strings. Each argument is forwarded directly
 *   to the underlying `proc_open` syscall by Symfony Process — no shell is
 *   spawned, no glob/redirection/substitution applied. Command injection is
 *   impossible by construction.
 * - **Typed exception on failure** : non-zero exit codes and timeouts are
 *   reported as {@see ShellExecutionException}, with the rich details kept
 *   on dedicated properties for server-side logging only (no leak to clients).
 * - **Cross-platform binary discovery** via {@see locate()} — replaces the
 *   duplicated `findLibreOfficeCommand()` / `findGhostscriptCommand()` logic
 *   present in the legacy `FileConversionService`.
 *
 * @see \Symfony\Component\Process\Process
 */
final class ShellExecutor implements ShellExecutorInterface
{
    /**
     * Default timeout in seconds for a single process invocation.
     *
     * Tuned for the longest legitimate conversion observed in production
     * (LibreOffice headless on large `.pptx`). Callers may override per
     * invocation via the `$timeout` parameter of {@see run()}.
     */
    public const DEFAULT_TIMEOUT = 120;

    /**
     * Short timeout for binary-lookup calls (`where` / `which`) inside
     * {@see locate()}. The lookup must be quick or it's effectively a
     * "not found" — no point waiting longer.
     */
    private const LOCATE_TIMEOUT = 5;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Execute an external command synchronously.
     *
     * @param array<int, string> $command  Argv-style array. The first element
     *                                     is the binary, the rest are individual
     *                                     arguments (never a single shell line).
     * @param string|null $cwd             Working directory. `null` keeps the
     *                                     current PHP process directory.
     * @param int $timeout                 Hard timeout in seconds. Process is
     *                                     killed and a ShellExecutionException
     *                                     is raised when reached.
     *
     * @throws ShellExecutionException If the process exits with a non-zero code
     *                                  or times out. The exception carries the
     *                                  command, stderr and exit code on typed
     *                                  readonly properties for server-side logs.
     */
    public function run(array $command, ?string $cwd = null, int $timeout = self::DEFAULT_TIMEOUT): ShellResult
    {
        $process = new Process($command, $cwd);
        $process->setTimeout((float) $timeout);

        try {
            $process->run();
        } catch (SymfonyProcessException $e) {
            // Catches the entire Symfony\Process exception family : timeout
            // (ProcessTimedOutException), missing binary (ProcessStartFailedException),
            // and any future addition. Uniformly wraps them in our typed
            // exception so consumers only ever have to catch ShellExecutionException.
            throw new ShellExecutionException(
                command: $command,
                stderr: $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $e->getMessage(),
                exitCode: -1,
                previous: $e,
            );
        }

        if (! $process->isSuccessful()) {
            throw new ShellExecutionException(
                command: $command,
                stderr: $process->getErrorOutput(),
                exitCode: $process->getExitCode() ?? -1,
            );
        }

        return new ShellResult(
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? 0,
        );
    }

    /**
     * Locate a binary on disk or in PATH from an ordered list of candidates.
     *
     * Absolute paths (those containing a slash) are checked first via
     * {@see is_file()} ; bare names fall back to a PATH lookup using `where`
     * on Windows or `which` on Unix-likes, run through {@see run()} so the
     * shell stays auditable and mockable.
     *
     * @param string $binaryName               Logical name used only for the
     *                                          "not found" debug log line.
     * @param array<int, string> $candidates   Ordered list of candidates. The
     *                                          first one that resolves wins.
     *
     * @return string|null Absolute path on success ; `null` if no candidate
     *                     resolves on the host.
     */
    public function locate(string $binaryName, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '/') || str_contains($candidate, '\\')) {
                if (is_file($candidate)) {
                    return $candidate;
                }
                continue;
            }

            $resolved = $this->findOnPath($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $this->logger?->debug(
            'Binary not found on host',
            ['binary' => $binaryName, 'candidates' => $candidates],
        );

        return null;
    }

    /**
     * Search PATH for a bare binary name using the OS-native locator.
     *
     * @return string|null Absolute path of the first match, or `null` if the
     *                     locator exits non-zero (binary absent).
     */
    private function findOnPath(string $candidate): ?string
    {
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';

        try {
            $result = $this->run([$finder, $candidate], null, self::LOCATE_TIMEOUT);
        } catch (ShellExecutionException) {
            return null;
        }

        $firstLine = strtok($result->stdout, "\n");

        if ($firstLine === false) {
            return null;
        }

        $path = trim($firstLine);

        return $path !== '' ? $path : null;
    }
}
