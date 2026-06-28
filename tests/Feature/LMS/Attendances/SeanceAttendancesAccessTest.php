<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Attendances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sécurité (contrôle d'accès) — `GET /lms/seances/{id}/attendances` liste les
 * emails des participants. Réservé au staff (`role:enseignant,coordinateur,
 * superAdmin`, comme la voisine `/seances/history`). Sans ce garde, un étudiant
 * pouvait lister les emails de toute séance (le service ne bloquait que
 * l'enseignant non-propriétaire).
 *
 * @see routes/api.php (lms.seances.attendances)
 */
final class SeanceAttendancesAccessTest extends TestCase
{
    use RefreshDatabase;

    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->seance = Seance::factory()->forTeacher(777)->create([
            'institution_id' => $institution->id,
        ]);
    }

    private function userWithRole(string $role, ?int $klassciId = null): User
    {
        return User::factory()->create([
            'institution_id' => $this->seance->institution_id,
            'role' => $role,
            'klassci_id' => $klassciId,
        ]);
    }

    public function test_student_is_forbidden_from_seance_attendances(): void
    {
        Sanctum::actingAs($this->userWithRole('etudiant', 888));

        // Bloqué par le middleware de rôle AVANT d'atteindre le service.
        $this->getJson("/api/lms/seances/{$this->seance->id}/attendances")
            ->assertStatus(403);
    }

    public function test_staff_passes_the_role_gate(): void
    {
        // Enseignant propriétaire (klassci_id == klassci_enseignant_id de la séance).
        Sanctum::actingAs($this->userWithRole('enseignant', 777));

        // Le garde de rôle laisse passer le staff (≠ 403). Le détail de la
        // réponse relève du service, déjà couvert ailleurs.
        $status = $this->getJson("/api/lms/seances/{$this->seance->id}/attendances")->status();
        $this->assertNotSame(403, $status, 'Le staff ne doit pas être bloqué par le garde de rôle.');
    }
}
