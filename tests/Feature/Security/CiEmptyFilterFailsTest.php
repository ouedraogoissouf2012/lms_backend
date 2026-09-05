<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * #702 — une porte `--filter` ne doit plus être verte si le test a disparu.
 */
final class CiEmptyFilterFailsTest extends TestCase
{
    public function test_phpunit_fail_on_empty_test_suite_exits_nonzero(): void
    {
        $phpunit = base_path('vendor/bin/phpunit');
        $output = [];
        $code = 0;
        exec(
            escapeshellarg($phpunit)
            .' --filter CiEmptyFilterTargetThatDoesNotExist --fail-on-empty-test-suite --no-coverage 2>&1',
            $output,
            $code,
        );

        self::assertNotSame(0, $code, implode("\n", $output));
    }

    public function test_openapi_ci_job_uses_fail_on_empty_test_suite(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/security.yml'));

        self::assertStringContainsString(
            'vendor/bin/phpunit --filter OpenApiSyncTest --fail-on-empty-test-suite',
            $yml,
        );
    }

    public function test_pull_request_trigger_is_not_limited_to_lms(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/security.yml'));
        $on = preg_split('/^jobs:/m', $yml, 2)[0] ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/pull_request:\s*\n\s*branches:\s*\n\s*-\s*lms/m',
            $on,
        );
    }

    public function test_file_size_guard_runs_on_push(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/security.yml'));
        $pos = strpos($yml, 'file-size-guard:');
        self::assertNotFalse($pos);
        $chunk = substr($yml, $pos, 800);

        self::assertStringNotContainsString(
            "if: github.event_name == 'pull_request'",
            $chunk,
        );
    }
}
