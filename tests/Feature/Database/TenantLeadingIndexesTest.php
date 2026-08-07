<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PERF #519 — vérifie que la migration des index composites tenant-leading
 * crée bien les 9 index attendus (nom + colonnes dans l'ordre exact).
 *
 * L'ORDRE des colonnes est ce qui distingue un composite utilisable d'un
 * composite inutile : c'est donc l'assertion centrale (pas seulement la
 * présence). Driver-agnostique via `Schema::getIndexes()`.
 *
 * @see database/migrations/2026_08_07_000001_add_tenant_leading_composite_indexes.php
 */
final class TenantLeadingIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function compositeIndexProvider(): array
    {
        return [
            'seances (institution_id, visio_started_at)' => [
                'seances', 'seances_inst_visio_started_idx',
                ['institution_id', 'visio_started_at'],
            ],
            'seances (institution_id, is_active, date_seance)' => [
                'seances', 'seances_inst_active_date_idx',
                ['institution_id', 'is_active', 'date_seance'],
            ],
            'lessons (institution_id, status, published_at)' => [
                'lessons', 'lessons_inst_status_published_idx',
                ['institution_id', 'status', 'published_at'],
            ],
            'lessons (enseignant_id, status)' => [
                'lessons', 'lessons_teacher_status_idx',
                ['enseignant_id', 'status'],
            ],
            'evaluations (institution_id, klassci_classe_id, is_published, status)' => [
                'evaluations', 'evaluations_inst_classe_pub_status_idx',
                ['institution_id', 'klassci_classe_id', 'is_published', 'status'],
            ],
            'notifications (institution_id, created_at)' => [
                'notifications', 'notifications_inst_created_idx',
                ['institution_id', 'created_at'],
            ],
            'notifications (institution_id, type)' => [
                'notifications', 'notifications_inst_type_idx',
                ['institution_id', 'type'],
            ],
            'forum_topics (institution_id, status, is_resolved)' => [
                'forum_topics', 'forum_topics_inst_status_resolved_idx',
                ['institution_id', 'status', 'is_resolved'],
            ],
            'audit_logs (action, created_at)' => [
                'audit_logs', 'audit_logs_action_created_idx',
                ['action', 'created_at'],
            ],
        ];
    }

    /**
     * @param  list<string>  $expectedColumns
     */
    #[DataProvider('compositeIndexProvider')]
    public function test_composite_index_exists_with_expected_column_order(
        string $table,
        string $indexName,
        array $expectedColumns,
    ): void {
        $index = collect(Schema::getIndexes($table))
            ->first(static fn (array $i): bool => $i['name'] === $indexName);

        $this->assertNotNull($index, "Index composite `{$indexName}` absent de la table `{$table}`.");
        $this->assertSame(
            $expectedColumns,
            array_values($index['columns']),
            "Ordre/colonnes de `{$indexName}` inattendus — un composite mal ordonné est inutilisable.",
        );
    }
}
