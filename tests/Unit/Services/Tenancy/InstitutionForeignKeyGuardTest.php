<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tenancy;

use App\Services\Tenancy\InstitutionForeignKeyGuard;
use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Garde pré-vol #583 testée en isolation via un double de l'inspecteur (LSP) —
 * aucune base ni orphelin réel requis.
 */
#[CoversClass(InstitutionForeignKeyGuard::class)]
final class InstitutionForeignKeyGuardTest extends TestCase
{
    public function test_throws_when_orphans_present(): void
    {
        $guard = new InstitutionForeignKeyGuard($this->inspectorReporting(['lessons' => 3, 'files' => 1]));

        $this->expectException(RuntimeException::class);

        $guard->ensureNoOrphans(['lessons', 'files']);
    }

    public function test_abort_message_lists_offending_tables_and_points_to_audit_command(): void
    {
        $guard = new InstitutionForeignKeyGuard($this->inspectorReporting(['lessons' => 3]));

        try {
            $guard->ensureNoOrphans(['lessons']);
            self::fail('Une RuntimeException était attendue.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('lessons', $e->getMessage());
            self::assertStringContainsString('3 ligne(s) orpheline(s)', $e->getMessage());
            self::assertStringContainsString('institutions:audit-orphans', $e->getMessage());
        }
    }

    public function test_does_not_throw_when_no_orphan(): void
    {
        $guard = new InstitutionForeignKeyGuard($this->inspectorReporting([]));

        $guard->ensureNoOrphans(['lessons', 'files']);

        self::assertTrue(true, 'Aucune exception ne doit être levée sur des données saines.');
    }

    /**
     * Double d'inspecteur : `scopedTablesPresent` renvoie les tables telles
     * quelles ; `orphans` renvoie le rapport injecté.
     *
     * @param  array<string, int>  $orphans
     */
    private function inspectorReporting(array $orphans): InstitutionIntegrityInspectorInterface
    {
        return new class($orphans) implements InstitutionIntegrityInspectorInterface
        {
            /** @param array<string, int> $orphans */
            public function __construct(private readonly array $orphans)
            {
            }

            public function scopedTablesPresent(array $tables): array
            {
                return array_values($tables);
            }

            public function nullCount(string $table): int
            {
                return 0;
            }

            public function orphanCount(string $table): int
            {
                return $this->orphans[$table] ?? 0;
            }

            public function report(array $tables): array
            {
                return [];
            }

            public function orphans(array $tables): array
            {
                return $this->orphans;
            }

            public function hasInstitutionForeignKey(string $table): bool
            {
                return false;
            }
        };
    }
}
