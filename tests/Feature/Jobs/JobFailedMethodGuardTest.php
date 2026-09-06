<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Tests\TestCase;

/**
 * #708 — garde : tout job déclare failed(), avec dénominateur.
 */
final class JobFailedMethodGuardTest extends TestCase
{
    public function test_guard_reddens_when_nothing_is_inspected(): void
    {
        [$code, $output] = $this->runGuard(sys_get_temp_dir().'/jobs_missing_'.uniqid());

        self::assertSame(2, $code, $output);
        self::assertStringContainsString('rien inspecté', $output);
    }

    public function test_all_app_jobs_declare_failed(): void
    {
        [$code, $output] = $this->runGuard(base_path('app/Jobs'));

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/inspecté [1-9][0-9]* job/', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runGuard(string $dir): array
    {
        $output = [];
        $code = 0;
        exec(
            'php '.escapeshellarg(base_path('scripts/check-jobs-have-failed.php')).' '.escapeshellarg($dir).' 2>&1',
            $output,
            $code,
        );

        return [$code, implode("\n", $output)];
    }
}
