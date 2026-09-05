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
 * ## Ce que #701 a corrigé, et pourquoi ce fichier y a contribué
 *
 * `test_ignores_non_app_files` exigeait `exit 0` quand AUCUN fichier du
 * périmètre n'était fourni — c'est-à-dire quand la garde n'avait rien inspecté.
 * Un test peut entériner un défaut : celui-ci l'a fait, et il aurait fait
 * échouer le correctif.
 *
 * La règle est désormais : un garde-fou publie son dénominateur, et distingue
 * « rien à redire » (0) de « je n'ai rien regardé » (2).
 *
 * @see scripts/check-file-sizes.php
 * @see scripts/check-method-sizes.php
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

    /**
     * Remplace un test qui exigeait `exit 0` ici — donc qui verrouillait le faux
     * vert. Un fichier hors `app/` reste bien ignoré ; mais s'il est le SEUL
     * fourni, la garde n'a rien inspecté et doit le dire.
     */
    public function test_refuses_when_nothing_in_scope_was_given(): void
    {
        [$code, $output] = $this->runGuard([$this->tmpFile('config/huge.php', 400)]);

        $this->assertSame(2, $code, 'Aucun fichier du périmètre ⇒ code 2, jamais 0.');
        $this->assertStringContainsString("rien n'a été contrôlé", $output);
    }

    public function test_refuses_without_any_argument(): void
    {
        [$code] = $this->runGuard([]);

        $this->assertSame(2, $code, "Sans argument, la garde n'a pas pu travailler.");
    }

    /**
     * Le dénominateur EST la correction. Sans lui, on ne peut pas distinguer un
     * contrôle réussi d'un contrôle qui n'a pas eu lieu.
     */
    public function test_publishes_its_denominator(): void
    {
        [$code, $output] = $this->runGuard([$this->tmpFile('app/Small.php', 50)]);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/1 fichier\(s\) inspect/u', $output);
    }

    /** Un fichier du périmètre supprimé par la PR ne doit PAS faire rougir la garde. */
    public function test_deleted_in_scope_file_is_not_a_violation(): void
    {
        [$code] = $this->runGuard([sys_get_temp_dir() . '/fsg_absent/app/Parti.php']);

        $this->assertSame(0, $code, "Dans le perimetre mais supprime : ce n'est pas une violation.");
    }

    // ── Le jumeau : même défaut, même correction (#701) ──────────────────────

    /**
     * @param  array<int, string>  $files
     * @return array{0: int, 1: string}
     */
    private function runMethodGuard(array $files): array
    {
        $script = base_path('scripts/check-method-sizes.php');
        $args = implode(' ', array_map('escapeshellarg', $files));

        $output = [];
        $code = 0;
        exec('php ' . escapeshellarg($script) . ' ' . $args . ' 2>&1', $output, $code);

        return [$code, implode("
", $output)];
    }

    public function test_method_guard_refuses_without_any_argument(): void
    {
        [$code] = $this->runMethodGuard([]);

        $this->assertSame(2, $code, 'Le jumeau portait le même défaut.');
    }

    public function test_method_guard_publishes_its_denominator(): void
    {
        [$code, $output] = $this->runMethodGuard([base_path('app/Models/User.php')]);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/1 fichier\(s\) inspect/u', $output);
    }
}
