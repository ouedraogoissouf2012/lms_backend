<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Tenancy;

use App\Services\Tenancy\GardeDesInstitutionsNulles;
use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * #801 (PR 2) — la garde qui précède le passage de `institution_id` en NOT NULL.
 *
 * Fusion = déploiement : si elle annule en production, c'est le DÉPLOIEMENT qui échoue. Son
 * message doit donc se lire dans le journal de déploiement : les tables, leur compte, et la
 * commande d'audit. Elle ne supprime RIEN : une quarantaine automatique d'une table parente
 * détruirait ses enfants en CASCADE, sans archive (graphe mesuré pour #719).
 */
#[CoversClass(GardeDesInstitutionsNulles::class)]
final class GardeDesInstitutionsNullesTest extends TestCase
{
    public function test_sans_null_elle_laisse_passer(): void
    {
        $this->garde(['lessons' => 0, 'notifications' => 0])->exigerAucunNull(['lessons', 'notifications']);

        $this->addToAssertionCount(1);
    }

    public function test_devant_un_null_elle_annule_en_nommant_tables_comptes_et_commande(): void
    {
        try {
            $this->garde(['lessons' => 0, 'notifications' => 3, 'seances' => 1])
                ->exigerAucunNull(['lessons', 'notifications', 'seances']);
            self::fail('La garde devait annuler.');
        } catch (RuntimeException $annulation) {
            $message = $annulation->getMessage();
            self::assertStringContainsString('notifications : 3 ligne(s)', $message);
            self::assertStringContainsString('seances : 1 ligne(s)', $message);
            self::assertStringNotContainsString('lessons', $message, 'Une table sans NULL n\'est pas citée.');
            self::assertStringContainsString('php artisan institutions:audit-orphans', $message);
            self::assertStringContainsString('Aucune ligne n\'a été supprimée', $message);
        }
    }

    public function test_une_table_absente_du_schema_n_est_pas_comptee(): void
    {
        $this->garde(['lessons' => 0])->exigerAucunNull(['lessons', 'table_disparue']);

        $this->addToAssertionCount(1);
    }

    /** @param  array<string, int>  $nulls  Les tables présentes et leur nombre de NULL. */
    private function garde(array $nulls): GardeDesInstitutionsNulles
    {
        return new GardeDesInstitutionsNulles(new class($nulls) implements InstitutionIntegrityInspectorInterface
        {
            /** @param  array<string, int>  $nulls */
            public function __construct(private readonly array $nulls) {}

            public function scopedTablesPresent(array $tables): array
            {
                return array_values(array_filter($tables, fn (string $table): bool => array_key_exists($table, $this->nulls)));
            }

            public function nullCount(string $table): int
            {
                return $this->nulls[$table] ?? 0;
            }

            public function orphanCount(string $table): int
            {
                return 0;
            }

            public function report(array $tables): array
            {
                return [];
            }

            public function orphans(array $tables): array
            {
                return [];
            }

            public function hasInstitutionForeignKey(string $table): bool
            {
                return true;
            }
        });
    }
}
