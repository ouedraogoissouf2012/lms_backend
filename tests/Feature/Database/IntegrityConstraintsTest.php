<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contraintes d'intégrité posées par #541.
 *
 * Les insertions passent par le Query Builder et non par Eloquent : ce qui est
 * vérifié ici est la garantie de la BASE, pas le garde applicatif qu'elle vient
 * doubler. Les deux moteurs du projet appliquent réellement ces contraintes —
 * SQLite avec `foreign_key_constraints=true` (défaut projet, cf.
 * `config/database.php:38`) et MySQL 8.4 (jambe CI #574).
 */
final class IntegrityConstraintsTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- présences

    public function test_attendance_with_unknown_seance_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertAttendance(['seance_id' => 999999, 'user_id' => $this->student()->id]);
    }

    public function test_attendance_with_unknown_user_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insertAttendance(['seance_id' => Seance::factory()->create()->id, 'user_id' => 999999]);
    }

    public function test_attendance_with_existing_references_is_accepted(): void
    {
        $this->insertAttendance([
            'seance_id' => Seance::factory()->create()->id,
            'user_id' => $this->student()->id,
        ]);

        self::assertSame(1, DB::table('esbtp_attendance')->count());
    }

    public function test_deleting_a_seance_cascades_to_its_attendances(): void
    {
        $seance = Seance::factory()->create();
        $this->insertAttendance(['seance_id' => $seance->id, 'user_id' => $this->student()->id]);

        // Suppression PHYSIQUE (purge) — la suppression métier est un soft delete.
        DB::table('seances')->where('id', $seance->id)->delete();

        self::assertSame(0, DB::table('esbtp_attendance')->count());
    }

    // -------------------------------------------------- lien KLASSCI évaluation

    public function test_second_live_evaluation_for_the_same_klassci_link_is_rejected(): void
    {
        $first = $this->evaluation(institutionId: $this->institution()->id, klassciId: 4242);

        $this->expectException(QueryException::class);

        $this->duplicateOf($first);
    }

    public function test_klassci_link_can_be_reused_once_the_previous_evaluation_is_soft_deleted(): void
    {
        $first = $this->evaluation(institutionId: $this->institution()->id, klassciId: 4242);
        $first->delete();

        $this->duplicateOf($first);

        self::assertSame(1, Evaluation::withoutGlobalScopes()->whereNull('deleted_at')->count());
        self::assertSame(2, Evaluation::withoutGlobalScopes()->withTrashed()->count());
    }

    public function test_two_institutions_may_carry_the_same_klassci_evaluation_id(): void
    {
        $this->evaluation(institutionId: $this->institution()->id, klassciId: 4242);
        $this->evaluation(institutionId: $this->institution()->id, klassciId: 4242);

        self::assertSame(2, Evaluation::withoutGlobalScopes()->count());
    }

    public function test_lms_only_evaluations_without_klassci_link_are_unlimited(): void
    {
        $institutionId = $this->institution()->id;

        $this->evaluation(institutionId: $institutionId, klassciId: null);
        $this->evaluation(institutionId: $institutionId, klassciId: null);
        $this->evaluation(institutionId: $institutionId, klassciId: null);

        self::assertSame(3, Evaluation::withoutGlobalScopes()->count());
    }

    public function test_generated_guard_column_never_leaks_into_the_api_payload(): void
    {
        $evaluation = $this->evaluation(institutionId: $this->institution()->id, klassciId: 4242);

        self::assertArrayNotHasKey('klassci_link_guard', $evaluation->fresh()?->toArray() ?? []);
    }

    // ------------------------------------------------- inscription classe/élève

    public function test_second_enrollment_of_the_same_student_in_the_same_classe_is_rejected(): void
    {
        $classe = Classe::factory()->create();
        $student = $this->student();

        $this->enroll($classe->id, $student->id);

        $this->expectException(QueryException::class);

        $this->enroll($classe->id, $student->id);
    }

    public function test_a_student_may_be_enrolled_in_several_classes(): void
    {
        $student = $this->student();

        $this->enroll(Classe::factory()->create()->id, $student->id);
        $this->enroll(Classe::factory()->create()->id, $student->id);

        self::assertSame(2, DB::table('classe_etudiant')->count());
    }

    // ------------------------------------------------------------------ helpers

    private function institution(): Institution
    {
        return Institution::factory()->create();
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'etudiant']);
    }

    /**
     * @param  array{seance_id: int, user_id: int}  $references
     */
    private function insertAttendance(array $references): void
    {
        DB::table('esbtp_attendance')->insert($references + [
            'is_validated' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function evaluation(int $institutionId, ?int $klassciId): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id' => $institutionId,
            'klassci_evaluation_id' => $klassciId,
        ]);
    }

    /**
     * Réplique la ligne au niveau BASE : on court-circuite Eloquent pour éprouver
     * la contrainte elle-même, pas le garde applicatif de 409.
     */
    private function duplicateOf(Evaluation $evaluation): void
    {
        DB::table('evaluations')->insert([
            'institution_id' => $evaluation->institution_id,
            'klassci_evaluation_id' => $evaluation->klassci_evaluation_id,
            'klassci_matiere_id' => $evaluation->klassci_matiere_id,
            'klassci_classe_id' => $evaluation->klassci_classe_id,
            'titre' => 'Duplicata',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enroll(int $classeId, int $userId): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classeId,
            'user_id' => $userId,
            'statut' => 'actif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
