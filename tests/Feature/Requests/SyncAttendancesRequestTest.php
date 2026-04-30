<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncAttendancesRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
    }

    public function test_unauthenticated_cannot_sync_attendances(): void
    {
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'etudiant_id' => 100,
                    'statut' => 'present',
                ],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_klassci_token_cannot_sync(): void
    {
        $user = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => null,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
        ]);

        $response->assertStatus(403);
    }

    public function test_valid_sync_request(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'etudiant_id' => 100,
                    'statut' => 'present',
                    'joined_at' => now()->format('Y-m-d H:i:s'),
                    'left_at' => now()->addHour()->format('Y-m-d H:i:s'),
                    'duration_minutes' => 60,
                ],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_missing_seance_cours_id_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'date' => now()->format('Y-m-d'),
            'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.seance_cours_id'));
    }

    public function test_invalid_seance_cours_id_type_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 'not_integer',
            'date' => now()->format('Y-m-d'),
            'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.seance_cours_id'));
    }

    public function test_missing_date_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.date'));
    }

    public function test_invalid_date_format_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => 'invalid_date',
            'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.date'));
    }

    public function test_missing_participants_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.participants'));
    }

    public function test_empty_participants_array_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.participants'));
    }

    public function test_missing_etudiant_id_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'statut' => 'present',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors')['participants.0.etudiant_id']);
    }

    public function test_missing_statut_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'etudiant_id' => 100,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors')['participants.0.statut']);
    }

    public function test_invalid_statut_value_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'etudiant_id' => 100,
                    'statut' => 'invalid_status',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors')['participants.0.statut']);
    }

    public function test_multiple_participants_with_different_statuts(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                ['etudiant_id' => 100, 'statut' => 'present'],
                ['etudiant_id' => 101, 'statut' => 'absent'],
                ['etudiant_id' => 102, 'statut' => 'retard'],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_optional_dates_and_duration(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/attendances/from-video-session', [
            'seance_cours_id' => 1,
            'date' => now()->format('Y-m-d'),
            'participants' => [
                [
                    'etudiant_id' => 100,
                    'statut' => 'present',
                    'joined_at' => now()->format('Y-m-d H:i:s'),
                    'left_at' => now()->addHour()->format('Y-m-d H:i:s'),
                    'duration_minutes' => 60,
                ],
                [
                    'etudiant_id' => 101,
                    'statut' => 'absent',
                ],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }
}
