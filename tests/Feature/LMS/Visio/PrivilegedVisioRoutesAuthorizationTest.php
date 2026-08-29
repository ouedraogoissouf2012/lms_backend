<?php

namespace Tests\Feature\LMS\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivilegedVisioRoutesAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Seance $seance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        $this->institution = Institution::create([
            'slug' => 'school-privileged-visio',
            'name' => 'School Privileged Visio',
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'logo_url' => 'https://example.test/logo.png',
            'primary_color' => '#000000',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
        app(TenantManager::class)->set($this->institution);
        $this->owner = $this->user('enseignant', 777);
        $this->seance = Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 777,
            'klassci_classe_id' => 55,
            'visio_enabled' => true,
        ]);
    }

    public function test_student_cannot_validate_participant(): void
    {
        $student = $this->user('etudiant', 101);
        Sanctum::actingAs($student);

        $this->postJson("/api/lms/seances/{$this->seance->id}/validate-participant", [
            'user_id' => $student->id,
        ])->assertStatus(403);
    }

    public function test_non_owner_teacher_cannot_validate_participant(): void
    {
        Sanctum::actingAs($this->user('enseignant', 999));

        $this->postJson("/api/lms/seances/{$this->seance->id}/validate-participant", [
            'user_id' => $this->owner->id,
        ])->assertStatus(403);
    }

    public function test_owner_teacher_can_validate_participant(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/lms/seances/{$this->seance->id}/validate-participant", [
            'user_id' => $this->owner->id,
        ])->assertOk()->assertJsonPath('authorized', true);
    }

    public function test_owner_cannot_validate_user_from_another_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $foreignStudent = User::factory()->student()->create([
            'institution_id' => $otherInstitution->id,
            'klassci_id' => 101,
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/lms/seances/{$this->seance->id}/validate-participant", [
            'user_id' => $foreignStudent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_student_cannot_sync_video_attendances(): void
    {
        Sanctum::actingAs($this->user('etudiant', 101));

        $this->postJson('/api/lms/attendances/from-video-session', $this->syncPayload(101))
            ->assertStatus(403);
    }

    public function test_non_owner_teacher_cannot_sync_video_attendances(): void
    {
        Sanctum::actingAs($this->user('enseignant', 999));

        $this->postJson('/api/lms/attendances/from-video-session', $this->syncPayload(101))
            ->assertStatus(403);
    }

    public function test_owner_teacher_can_sync_video_attendance_for_class_student(): void
    {
        $student = $this->user('etudiant', 101);
        $this->enrollInSeanceClasse($student);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/lms/attendances/from-video-session', $this->syncPayload(101))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('esbtp_attendance', [
            'seance_id' => $this->seance->id,
            'user_id' => $student->id,
            'klassci_etudiant_id' => 101,
            'status' => 'disconnected',
        ]);
    }

    public function test_owner_teacher_cannot_sync_video_attendance_for_student_outside_class(): void
    {
        $this->user('etudiant', 101);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/lms/attendances/from-video-session', $this->syncPayload(101))
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    private function user(string $role, int $klassciId): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_id' => $klassciId,
            'klassci_token' => 'token-'.$klassciId,
        ]);
    }

    private function enrollInSeanceClasse(User $student): void
    {
        $classe = Classe::create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 55,
            'code' => 'CLS-55',
            'libelle' => 'Classe 55',
            'effectif' => 1,
        ]);
        $classe->etudiants()->attach($student->id, ['statut' => 'actif']);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPayload(int $klassciStudentId): array
    {
        return [
            'seance_cours_id' => $this->seance->id,
            'date' => '2026-07-06',
            'participants' => [[
                'etudiant_id' => $klassciStudentId,
                'statut' => 'present',
                'joined_at' => '2026-07-06 10:00:00',
                'left_at' => '2026-07-06 11:00:00',
                'duration_minutes' => 60,
            ]],
        ];
    }
}
