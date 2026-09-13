<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #726 — export séance par id local, PDF et Excel.
 */
final class SeanceAttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_owner_can_export_pdf_and_excel_for_a_local_seance(): void
    {
        [$teacher, $seance] = $this->ownedLocalSeance();
        ESBTPAttendance::factory()->create([
            'institution_id' => $seance->institution_id,
            'seance_id' => $seance->id,
            'user_id' => User::factory()->student()->create([
                'institution_id' => $seance->institution_id,
            ])->id,
        ]);

        $this->withToken($teacher->createToken('726')->plainTextToken)
            ->postJson('/api/admin/reports/attendance', [
                'seance_id' => $seance->id,
                'format' => 'pdf',
            ])
            ->assertOk();

        $this->withToken($teacher->createToken('726')->plainTextToken)
            ->postJson('/api/admin/reports/attendance', [
                'seance_id' => $seance->id,
                'format' => 'excel',
            ])
            ->assertOk();
    }

    public function test_non_owner_teacher_is_forbidden(): void
    {
        [$teacher, $seance] = $this->ownedLocalSeance();
        $other = User::factory()->teacher()->create([
            'institution_id' => $seance->institution_id,
        ]);

        $this->withToken($other->createToken('726')->plainTextToken)
            ->postJson('/api/admin/reports/attendance', [
                'seance_id' => $seance->id,
                'format' => 'pdf',
            ])
            ->assertForbidden();

        $this->assertNotNull($teacher->id);
    }

    public function test_other_institution_seance_is_forbidden(): void
    {
        [, $seanceA] = $this->ownedLocalSeance();
        $schoolB = Institution::factory()->create();
        $teacherB = User::factory()->teacher()->create(['institution_id' => $schoolB->id]);
        app(TenantManager::class)->set($schoolB);

        $this->withToken($teacherB->createToken('726')->plainTextToken)
            ->postJson('/api/admin/reports/attendance', [
                'seance_id' => $seanceA->id,
                'format' => 'pdf',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Seance}
     */
    private function ownedLocalSeance(): array
    {
        $school = Institution::factory()->create();
        app(TenantManager::class)->set($school);
        $teacher = User::factory()->teacher()->create(['institution_id' => $school->id]);
        $seance = Seance::factory()->create([
            'institution_id' => $school->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'created_by' => $teacher->id,
        ]);

        return [$teacher, $seance];
    }
}
