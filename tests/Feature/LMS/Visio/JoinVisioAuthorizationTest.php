<?php

namespace Tests\Feature\LMS\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JoinVisioAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        Config::set('services.klassci.url', 'https://klassci.test');
        $this->institution = Institution::create([
            'slug' => 'school-join-visio',
            'name' => 'School Join Visio',
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'logo_url' => 'https://example.test/logo.png',
            'primary_color' => '#000000',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
        app(TenantManager::class)->set($this->institution);
    }

    public function test_non_enrolled_student_cannot_join_active_visio(): void
    {
        $student = $this->user('etudiant', 'outside@example.test');
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['inside@example.test']);
        Sanctum::actingAs($student);

        $this->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'not_enrolled');

        $this->assertDatabaseMissing('esbtp_attendance', [
            'seance_id' => $seance->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_enrolled_student_can_join_and_is_validated(): void
    {
        $student = $this->user('etudiant', 'inside@example.test');
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['inside@example.test']);
        Sanctum::actingAs($student);

        $this->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('esbtp_attendance', [
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'status' => 'connected',
            'is_validated' => true,
            'is_observer' => false,
        ]);
    }

    public function test_coordinateur_can_join_as_observer(): void
    {
        $coordinateur = $this->user('coordinateur', 'coord@example.test');
        $seance = $this->activeSeance();
        Sanctum::actingAs($coordinateur);

        $this->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('esbtp_attendance', [
            'seance_id' => $seance->id,
            'user_id' => $coordinateur->id,
            'status' => 'connected',
            'is_validated' => true,
            'is_observer' => true,
        ]);
    }

    private function user(string $role, string $email): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'email' => $email,
            'klassci_token' => 'fake-token',
        ]);
    }

    private function activeSeance(): Seance
    {
        return Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => 12345,
            'klassci_classe_id' => 55,
            'is_active' => true,
        ]);
    }

    /**
     * @param list<string> $emails
     */
    private function fakeEnrolledStudents(array $emails): void
    {
        Http::fake([
            'https://klassci.test/classes/55/etudiants' => Http::response([
                'data' => array_map(
                    static fn (string $email): array => ['email' => $email],
                    $emails,
                ),
            ], 200),
        ]);
    }
}
