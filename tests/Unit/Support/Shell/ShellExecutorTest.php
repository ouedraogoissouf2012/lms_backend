<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Shell;

use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutor;
use App\Support\Shell\ShellResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Issue #79 Phase A — contract verification for ShellExecutor.
 *
 * Five scenarios drawn from the requirements (Req 7 + Req 9) :
 *  1. A successful command returns a populated ShellResult.
 *  2. A failing command (non-zero exit OR missing binary) raises a typed
 *     ShellExecutionException — never the underlying Symfony exception.
 *  3. A command that exceeds its timeout raises ShellExecutionException.
 *  4. locate() returns the first existing absolute path.
 *  5. locate() returns null when no candidate resolves.
 *
 * Uses {@see PHP_BINARY} so the suite stays cross-platform : we always
 * have a PHP interpreter at a known path on every CI runner.
 */
#[CoversClass(ShellExecutor::class)]
final class ShellExecutorTest extends TestCase
{
    private ShellExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new ShellExecutor();
    }

    public function test_run_returns_shell_result_on_successful_command(): void
    {
        $result = $this->executor->run([PHP_BINARY, '-r', 'echo "ok";']);

        $this->assertInstanceOf(ShellResult::class, $result);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame(0, $result->exitCode);
    }

    public function test_run_throws_shell_execution_exception_when_command_exits_non_zero(): void
    {
        $this->expectException(ShellExecutionException::class);

        $this->executor->run([PHP_BINARY, '-r', 'exit(42);']);
    }

    public function test_shell_execution_exception_carries_command_and_exit_code(): void
    {
        try {
            $this->executor->run([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(7);']);
            $this->fail('Expected ShellExecutionException');
        } catch (ShellExecutionException $e) {
            $this->assertSame(7, $e->exitCode);
            $this->assertSame([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(7);'], $e->command);
            $this->assertStringContainsString('boom', $e->stderr);
        }
    }

    public function test_run_throws_shell_execution_exception_on_timeout(): void
    {
        // Spawn a PHP process that sleeps longer than the 1-second timeout
        // we pass to run(). The wrapper must convert Symfony's
        // ProcessTimedOutException into our typed ShellExecutionException.
        try {
            $this->executor->run([PHP_BINARY, '-r', 'sleep(3);'], null, 1);
            $this->fail('Expected ShellExecutionException due to timeout');
        } catch (ShellExecutionException $e) {
            $this->assertSame(-1, $e->exitCode);
            $this->assertNotNull($e->getPrevious(), 'Symfony exception should be chained for traces.');
        }
    }

    public function test_locate_returns_absolute_path_for_existing_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sh-locate-');
        $this->assertNotFalse($tmpFile, 'tempnam should provide a real path.');

        try {
            $found = $this->executor->locate('temp-fixture', [$tmpFile]);
            $this->assertSame($tmpFile, $found);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_locate_returns_null_when_no_candidate_resolves(): void
    {
        $found = $this->executor->locate('absent-binary', [
            '/definitely/not/an/existing/path/binary-xyz',
            'this-binary-name-doesnt-exist-anywhere-12345',
        ]);

        $this->assertNull($found);
    }

    /**
     * Priority contract : when several candidates exist on disk, the first
     * one in the array must win. This matters for the production binary
     * lookup where newer paths (e.g. `gs10.06.0/...`) come before older
     * versions in the candidate list.
     */
    public function test_locate_picks_the_first_resolvable_candidate(): void
    {
        $firstChoice  = tempnam(sys_get_temp_dir(), 'sh-locate-first-');
        $secondChoice = tempnam(sys_get_temp_dir(), 'sh-locate-second-');
        $this->assertNotFalse($firstChoice);
        $this->assertNotFalse($secondChoice);

        try {
            $found = $this->executor->locate('priority-test', [
                $firstChoice,
                $secondChoice,
            ]);
            $this->assertSame($firstChoice, $found, 'First candidate must win when both exist.');
        } finally {
            @unlink($firstChoice);
            @unlink($secondChoice);
        }
    }
}
