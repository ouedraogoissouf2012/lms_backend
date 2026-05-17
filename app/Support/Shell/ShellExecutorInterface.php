<?php

declare(strict_types=1);

namespace App\Support\Shell;

/**
 * Contract for any class that runs external processes for the LMS.
 *
 * Concrete implementations (see {@see ShellExecutor}) are responsible for
 * passing arguments as `array<int, string>` to the underlying syscall —
 * never a single shell string — so command injection is impossible by
 * construction.
 *
 * Consumers (converters, helpers) MUST depend on this interface rather
 * than on the concrete class, which lets them be unit-tested with
 * lightweight mocks (Mockery, Prophecy, hand-rolled stubs) without the
 * overhead of spawning real processes.
 */
interface ShellExecutorInterface
{
    /**
     * Execute an external command synchronously.
     *
     * @param array<int, string> $command  Argv-style array.
     * @param string|null $cwd             Working directory ; `null` keeps PHP's CWD.
     * @param int $timeout                 Hard timeout in seconds.
     *
     * @throws ShellExecutionException     On non-zero exit, timeout or start failure.
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 120): ShellResult;

    /**
     * Locate a binary on disk or in PATH from an ordered list of candidates.
     *
     * @param string $binaryName              Logical name used only for "not found" log.
     * @param array<int, string> $candidates  Ordered list of absolute paths or bare names.
     *
     * @return string|null Absolute path on success, `null` if no candidate resolves.
     */
    public function locate(string $binaryName, array $candidates): ?string;
}
