<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Garde-fou taille de fichiers (issue #276) — teste `scripts/check-file-sizes.php`.
 *
 * Vérifie que le script applique bien §1.1 (≤300) / §5 (modèles ≤150) et
 * n'échoue (exit 1) que sur les vrais dépassements de code applicatif.
 *
 * @see scripts/check-file-sizes.php
 */
final class FileSizeGuardTest extends TestCase
{
    /**
     * @param  array<int, string>  $files
     * @return array{0: int, 1: string}  [exitCode, output]
     */
    private function runGuard(array $files): array
    {
        $script = base_path('scripts/check-file-sizes.php');
        $args = implode(' ', array_map('escapeshellarg', $files));

        $output = [];
        $code = 0;
        exec('php ' . escapeshellarg($script) . ' ' . $args . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function tmpFile(string $relative, int $lines): string
    {
        $base = sys_get_temp_dir() . '/fsg_' . uniqid();
        $path = $base . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, str_repeat("// ligne\n", $lines));

        return $path;
    }

    public function test_passes_when_app_file_within_limit(): void
    {
        [$code] = $this->runGuard([$this->tmpFile('app/Small.php', 50)]);

        $this->assertSame(0, $code);
    }

    public function test_fails_when_app_file_exceeds_300(): void
    {
        [$code, $output] = $this->runGuard([$this->tmpFile('app/Big.php', 301)]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('max 300', $output);
    }

    public function test_fails_when_model_exceeds_150(): void
    {
        [$code, $output] = $this->runGuard([$this->tmpFile('app/Models/Huge.php', 160)]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('max 150', $output);
    }

    public function test_model_under_150_passes(): void
    {
        [$code] = $this->runGuard([$this->tmpFile('app/Models/Ok.php', 140)]);

        $this->assertSame(0, $code);
    }

    public function test_ignores_non_app_files(): void
    {
        // Un gros fichier hors app/ (config, migration, spec) n'est PAS contrôlé.
        [$code] = $this->runGuard([$this->tmpFile('config/huge.php', 400)]);

        $this->assertSame(0, $code);
    }
}
