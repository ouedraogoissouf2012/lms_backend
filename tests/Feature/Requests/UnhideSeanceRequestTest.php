<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

class UnhideSeanceRequestTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $institution;

    private User $student;

    private User $teacher;

    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->seance = Seance::factory()->forInstitution($this->institution)->create();
        // `SeanceUserHidden::hide()` hérite l'école du tenant, que la route pose ; posé ici
        // pour les lignes que le test prépare sans requête (#801).
        $this->app->make(TenantManager::class)->set($this->institution);
    }

    public function test_unauthenticated_cannot_unhide(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(401);
    }

    public function test_teacher_cannot_unhide(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(403);
    }

    public function test_student_can_unhide_hidden_seance(): void
    {
        // First hide the seance
        SeanceUserHidden::hide($this->seance->id, $this->student->id);

        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_unhide_nonexistent_seance_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/lms/seances/99999/unhide');

        $response->assertStatus(404);
    }

    public function test_unhide_not_hidden_seance_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        // Should return 404 because seance wasn't hidden for this student
        $response->assertStatus(404);
    }

    /**
     * #1566 : l'id local est l'espace d'une école AUTONOME. En école KLASSCI, il ne désigne
     * plus rien (ChaquePorteDesigneLaSeanceDeSonEspaceTest). Vrai jeton : sans tenant, la
     * source est celle de KLASSCI.
     */
    public function test_student_can_unhide_by_local_id(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $eleve = User::factory()->student()->for($ecole)->create();
        $seance = Seance::factory()->forInstitution($ecole)->create(['klassci_seance_id' => null]);
        // Le tenant du setUp est l'école KLASSCI : la ligne préparée porte l'école de la séance.
        $this->app->make(TenantManager::class)->set($ecole);
        SeanceUserHidden::hide($seance->id, $eleve->id);

        $this->asTenant($eleve)->postJson("/api/lms/seances/{$seance->id}/unhide")->assertOk();

        $this->assertDatabaseMissing('seance_user_hidden', ['seance_id' => $seance->id, 'user_id' => $eleve->id]);
    }

    public function test_hide_then_unhide_cycle(): void
    {
        Sanctum::actingAs($this->student);

        // Hide seance
        $hideResponse = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");
        $this->assertTrue(in_array($hideResponse->status(), [200, 201]));

        // Verify it's hidden
        $this->assertTrue(SeanceUserHidden::isHidden($this->seance->id, $this->student->id));

        // Unhide seance
        $unhideResponse = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");
        $this->assertTrue(in_array($unhideResponse->status(), [200, 201]));

        // Verify it's not hidden anymore
        $this->assertFalse(SeanceUserHidden::isHidden($this->seance->id, $this->student->id));
    }

    public function test_coordinator_cannot_unhide(): void
    {
        $coordinator = User::factory()->coordinator()->for($this->institution)->create();
        SeanceUserHidden::hide($this->seance->id, $coordinator->id);

        Sanctum::actingAs($coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(403);
    }
}
