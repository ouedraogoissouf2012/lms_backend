<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Issue #119 — end-to-end and ownership tests for the `klassci_enseignant_id`
 * separation. Verifies that a compromised KLASSCI server cannot grant a user
 * the ability to delete/publish/update another teacher's evaluations via the
 * volatile `klassci_data` blob.
 *
 * Spec: `.claude/specs/klassci-enseignant-id-separation/`
 *
 * @see \App\Http\Requests\DeleteEvaluationRequest
 * @see \App\Http\Requests\PublishEvaluationRequest
 * @see \App\Http\Requests\UpdateEvaluationRequest
 */
final class KlassciEnseignantIdSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Invoke `KlassciUserSynchronizer::sync()` directly to exercise REQ-3
     * without the multi-tenant login discovery machinery.
     */
    private function callSyncUserFromKlassci(array $klassciUser): User
    {
        return $this->app->make(KlassciUserSynchronizer::class)->sync(
            $klassciUser,
            'fake-klassci-token',
            'https://klassci.fake/api',
            $this->institution,
        );
    }

    private function teacher(int $klassciEnseignantId, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'institution_id'        => $this->institution->id,
            'role'                  => 'enseignant',
            'klassci_role'          => 'enseignant',
            'klassci_enseignant_id' => $klassciEnseignantId,
            'klassci_data'          => json_encode(['enseignant_id' => $klassciEnseignantId]),
        ], $extra));
    }

    private function evaluation(int $ownerKlassciEnseignantId): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id'        => $this->institution->id,
            'klassci_enseignant_id' => $ownerKlassciEnseignantId,
            'is_locked'             => false,
            'is_published'          => false,
        ]);
    }

    // REQ-7 #1 — CREATE writes klassci_enseignant_id from payload.
    public function test_create_initializes_klassci_enseignant_id_from_payload(): void
    {
        $user = $this->callSyncUserFromKlassci([
            'id'            => 7777,
            'nom'           => 'Teacher',
            'email'         => 't@school-a.fr',
            'role'          => 'enseignant',
            'enseignant_id' => 42,
        ]);

        self::assertSame(42, $user->klassci_enseignant_id);
    }

    // REQ-7 #2 — UPDATE does NOT overwrite klassci_enseignant_id.
    public function test_update_does_not_overwrite_klassci_enseignant_id(): void
    {
        $existing = $this->teacher(42, [
            'klassci_id' => 7777,
            'email'      => 't@school-a.fr',
        ]);

        $user = $this->callSyncUserFromKlassci([
            'id'            => 7777,
            'nom'           => 'Teacher',
            'email'         => 't@school-a.fr',
            'role'          => 'enseignant',
            'enseignant_id' => 999,    // attacker pushes impersonation
        ]);

        self::assertSame($existing->id, $user->id);
        self::assertSame(42, $user->fresh()->klassci_enseignant_id, 'klassci_enseignant_id MUST be preserved.');
    }

    // REQ-7 #4 — Owner CAN delete their own evaluation.
    public function test_delete_evaluation_authorized_for_owner_via_dedicated_column(): void
    {
        $teacher    = $this->teacher(42);
        $evaluation = $this->evaluation(42);

        Sanctum::actingAs($teacher);

        $this->deleteJson("/api/evaluations/{$evaluation->id}")
            ->assertStatus(200);
    }

    // REQ-7 #5 — Attacker scenario: klassci_data['enseignant_id'] is overwritten
    // by a compromised KLASSCI server, but the FormRequest reads the dedicated
    // column instead, so authorization fails.
    public function test_delete_evaluation_blocked_for_klassci_data_blob_attacker(): void
    {
        $teacher = $this->teacher(42, [
            // The blob is poisoned to match the victim's evaluation owner (999).
            // The dedicated column (42) is the source of truth.
            'klassci_data' => json_encode(['enseignant_id' => 999]),
        ]);
        $evaluation = $this->evaluation(999);   // owned by teacher 999

        Sanctum::actingAs($teacher);

        $this->deleteJson("/api/evaluations/{$evaluation->id}")
            ->assertStatus(403);
    }

    // REQ-7 #6 — Same attacker scenario on POST /publish.
    public function test_publish_evaluation_blocked_for_klassci_data_blob_attacker(): void
    {
        $teacher = $this->teacher(42, [
            'klassci_data' => json_encode(['enseignant_id' => 999]),
        ]);
        $evaluation = $this->evaluation(999);

        Sanctum::actingAs($teacher);

        $this->postJson("/api/evaluations/{$evaluation->id}/publish")
            ->assertStatus(403);
    }

    // REQ-7 #7 — Same attacker scenario on PUT /api/evaluations/{id}.
    public function test_update_evaluation_blocked_for_klassci_data_blob_attacker(): void
    {
        $teacher = $this->teacher(42, [
            'klassci_data' => json_encode(['enseignant_id' => 999]),
        ]);
        $evaluation = $this->evaluation(999);

        Sanctum::actingAs($teacher);

        $this->putJson("/api/evaluations/{$evaluation->id}", [
            'titre' => 'Hijacked title',
        ])->assertStatus(403);
    }

    // REQ-7 #9 — User with NULL klassci_enseignant_id cannot delete.
    public function test_user_without_klassci_enseignant_id_cannot_delete_evaluation(): void
    {
        $teacher = $this->teacher(0, [
            // Force NULL on the column even though factory default would set one.
            'klassci_enseignant_id' => null,
            'klassci_data'          => json_encode(['enseignant_id' => 999]),  // poisoned
        ]);
        $evaluation = $this->evaluation(999);

        Sanctum::actingAs($teacher);

        $this->deleteJson("/api/evaluations/{$evaluation->id}")
            ->assertStatus(403);
    }

    // REQ-7 #10 — Admin bypass preserved.
    public function test_admin_can_delete_evaluation_regardless_of_klassci_enseignant_id(): void
    {
        $admin = User::factory()->create([
            'institution_id'        => $this->institution->id,
            'role'                  => 'supradmin',
            'klassci_role'          => 'supradmin',
            'klassci_enseignant_id' => null,
        ]);
        $evaluation = $this->evaluation(999);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/evaluations/{$evaluation->id}")
            ->assertStatus(200);
    }
}
