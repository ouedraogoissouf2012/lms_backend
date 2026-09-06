<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * #709 — allow-list : Isolation / CrossTenant / Tenant + le test amiral.
 * Exit 2 si rien inspecté.
 */
final class IsolationTestsUseRealBearerTest extends TestCase
{
    public function test_guard_reddens_when_nothing_is_inspected(): void
    {
        [$code, $output] = $this->scan([]);

        self::assertSame(2, $code, $output);
        self::assertStringContainsString('rien inspecté', $output);
    }

    public function test_allowlisted_isolation_files_do_not_call_acting_as(): void
    {
        $files = $this->allowlist();
        [$code, $output] = $this->scan($files);

        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/inspecté [1-9][0-9]* fichier/', $output);
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        $root = base_path('tests/Feature');
        $matches = array_merge(
            glob($root.'/E2E/MultiTenantIsolationFlowTest.php') ?: [],
            glob($root.'/**/*Isolation*.php') ?: [],
            glob($root.'/**/*CrossTenant*.php') ?: [],
            glob($root.'/**/*Tenant*.php') ?: [],
        );

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<string>  $files
     * @return array{0: int, 1: string}
     */
    private function scan(array $files): array
    {
        if ($files === []) {
            return [2, "IsolationBearer: rien inspecté.\n"];
        }

        $offenders = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/Sanctum::actingAs\s*\(/', $src) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $n = count($files);
        $output = "IsolationBearer: inspecté {$n} fichier(s).\n";
        if ($offenders !== []) {
            return [1, $output.'actingAs interdit : '.implode(', ', $offenders)."\n"];
        }

        return [0, $output];
    }
}
