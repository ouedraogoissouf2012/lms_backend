<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendSessionReminderRequestTest extends TestCase
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

    public function test_unauthenticated_cannot_send_reminder(): void
    {
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_klassci_token_cannot_send(): void
    {
        $user = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => null,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
        ]);

        $response->assertStatus(403);
    }

    public function test_valid_reminder_request(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email', 'app'],
            'minutes_before' => 15,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_missing_seance_cours_id_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'channels' => ['email'],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.seance_cours_id'));
    }

    public function test_invalid_seance_cours_id_type_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 'not_integer',
            'channels' => ['email'],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.seance_cours_id'));
    }

    public function test_missing_channels_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.channels'));
    }

    public function test_empty_channels_array_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.channels'));
    }

    public function test_invalid_channel_value_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['invalid_channel'],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertTrue(
            isset($errors['channels.0']) || isset($errors['channels']),
            'Expected validation error for invalid channel value'
        );
    }

    public function test_single_channel(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_multiple_channels(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['whatsapp', 'email', 'sms', 'app'],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_without_minutes_before(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_with_minutes_before_zero(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
            'minutes_before' => 0,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_with_minutes_before_max(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
            'minutes_before' => 1440,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_minutes_before_exceeds_max_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
            'minutes_before' => 1441,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.minutes_before'));
    }

    public function test_negative_minutes_before_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 1,
            'channels' => ['email'],
            'minutes_before' => -1,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.minutes_before'));
    }
}
