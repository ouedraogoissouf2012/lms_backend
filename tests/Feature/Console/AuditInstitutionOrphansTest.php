<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\Lesson;
use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Commande `institutions:audit-orphans` (#583) — mesure préalable read-only.
 */
final class AuditInstitutionOrphansTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_emits_no_write_query(): void
    {
        $institution = Institution::factory()->create();
        Lesson::factory()->create(['institution_id' => $institution->id]);
        Lesson::factory()->create(['institution_id' => null]);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|alter|drop|create|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('institutions:audit-orphans')->assertSuccessful();

        self::assertSame([], $writes, 'La commande d\'audit doit être strictement en lecture seule.');
    }

    public function test_table_output_renders_counts_and_orphan_warning(): void
    {
        $this->app->instance(
            InstitutionIntegrityInspectorInterface::class,
            $this->inspectorReturning(['lessons' => ['null' => 2, 'orphan' => 3]]),
        );

        $this->artisan('institutions:audit-orphans')
            ->expectsOutputToContain('lessons')
            ->expectsOutputToContain('3 ligne(s) orpheline(s)')
            ->expectsOutputToContain('REFUSERA')
            ->assertSuccessful();
    }

    public function test_json_output_is_machine_readable(): void
    {
        $this->app->instance(
            InstitutionIntegrityInspectorInterface::class,
            $this->inspectorReturning(['lessons' => ['null' => 2, 'orphan' => 3]]),
        );

        $exit = Artisan::call('institutions:audit-orphans', ['--json' => true]);
        /** @var array<string, array{null: int, orphan: int}> $decoded */
        $decoded = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame(['lessons' => ['null' => 2, 'orphan' => 3]], $decoded);
    }

    /**
     * Double d'inspecteur renvoyant un rapport figé (rendu déterministe, sans
     * dépendre des données seedées).
     *
     * @param  array<string, array{null: int, orphan: int}>  $report
     */
    private function inspectorReturning(array $report): InstitutionIntegrityInspectorInterface
    {
        return new class($report) implements InstitutionIntegrityInspectorInterface
        {
            /** @param array<string, array{null: int, orphan: int}> $report */
            public function __construct(private readonly array $report)
            {
            }

            public function scopedTablesPresent(array $tables): array
            {
                return array_values($tables);
            }

            public function nullCount(string $table): int
            {
                return $this->report[$table]['null'] ?? 0;
            }

            public function orphanCount(string $table): int
            {
                return $this->report[$table]['orphan'] ?? 0;
            }

            public function report(array $tables): array
            {
                return $this->report;
            }

            public function orphans(array $tables): array
            {
                $orphans = [];
                foreach ($this->report as $table => $counts) {
                    if ($counts['orphan'] > 0) {
                        $orphans[$table] = $counts['orphan'];
                    }
                }

                return $orphans;
            }

            public function hasInstitutionForeignKey(string $table): bool
            {
                return false;
            }
        };
    }
}
