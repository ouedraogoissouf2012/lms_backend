<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Institution;
use App\Models\Lesson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Enforcement des clés étrangères `institution_id` (#583).
 *
 * ⚠️ Ces tests ne PROUVENT réellement l'enforcement que sur un moteur qui
 * applique les FK : SQLite avec `foreign_key_constraints=true` (défaut du
 * projet) et MySQL. La validation de référence est la jambe MySQL de la CI
 * (#574) — cf. `.claude/specs/583-fk-institution-id/requirements.md`.
 */
final class InstitutionForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_with_nonexistent_institution_id_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        // 999999 ne référence aucune institution → la base doit rejeter.
        Lesson::factory()->create(['institution_id' => 999999]);
    }

    public function test_null_institution_id_is_accepted(): void
    {
        $lesson = Lesson::factory()->create(['institution_id' => null]);

        self::assertDatabaseHas('lessons', ['id' => $lesson->id, 'institution_id' => null]);
    }

    public function test_deleting_institution_with_children_is_blocked_by_restrict(): void
    {
        $institution = Institution::factory()->create();
        Lesson::factory()->create(['institution_id' => $institution->id]);

        try {
            // forceDelete = suppression physique (le soft delete #567 n'atteint
            // pas la contrainte). RESTRICT doit bloquer et rien ne doit être perdu.
            $institution->forceDelete();
            self::fail('La suppression d\'une institution peuplée aurait dû être bloquée par ON DELETE RESTRICT.');
        } catch (QueryException) {
            $this->assertDatabaseHas('institutions', ['id' => $institution->id]);
            $this->assertDatabaseHas('lessons', ['institution_id' => $institution->id]);
        }
    }

    public function test_every_scoped_table_carries_the_institution_foreign_key(): void
    {
        /** @var list<string> $tables */
        $tables = config('tenancy.institution_scoped_tables');
        $missing = [];

        foreach ($tables as $table) {
            $hasFk = collect(Schema::getForeignKeys($table))
                ->contains(fn (array $fk): bool => in_array('institution_id', $fk['columns'], true));
            if (! $hasFk) {
                $missing[] = $table;
            }
        }

        self::assertSame([], $missing, 'Tables sans FK institution_id : '.implode(', ', $missing));
    }
}
