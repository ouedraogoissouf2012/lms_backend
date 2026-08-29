<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Issue #567 / #572 — `InstitutionCrudService::softDelete()` doit être une vraie
 * suppression LOGIQUE.
 *
 * Aujourd'hui (RED) : `$institution->delete()` sur un modèle sans `SoftDeletes`
 * = DELETE physique, sans garde `is_active`, sans révocation, sans audit.
 * Après correctif (GREEN) : active → 422 ; inactive → soft delete réversible,
 * jetons des utilisateurs du tenant révoqués, opération auditée.
 *
 * Bearer tokens RÉELS (pas `Sanctum::actingAs`) : le test de révocation doit
 * observer un vrai jeton devenir invalide — un guard mocké le masquerait.
 *
 * @see app/Services/Institution/InstitutionCrudService.php
 * @see app/Http/Controllers/API/InstitutionController.php
 */
final class InstitutionSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $supradmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('567')->plainTextToken];
    }

    private function memberTokenCount(User $user): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count();
    }

    public function test_deleting_active_institution_is_refused_422(): void
    {
        $institution = Institution::factory()->create(['is_active' => true]);

        $this->withHeaders($this->bearer($this->supradmin))
            ->deleteJson("/api/admin/institutions/{$institution->id}")
            ->assertStatus(422);

        // Une suppression n'est jamais un premier geste : la ligne reste intacte.
        $this->assertDatabaseHas('institutions', ['id' => $institution->id]);
    }

    public function test_deleting_inactive_institution_soft_deletes_it(): void
    {
        $institution = Institution::factory()->inactive()->create();

        $this->withHeaders($this->bearer($this->supradmin))
            ->deleteJson("/api/admin/institutions/{$institution->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('institutions', ['id' => $institution->id]);
    }

    public function test_soft_delete_revokes_tenant_users_sessions(): void
    {
        $institution = Institution::factory()->inactive()->create();
        $member = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
        $memberToken = $member->createToken('victim')->plainTextToken;

        // Le jeton du membre EXISTE avant suppression. (En HTTP ce membre d'une
        // institution INACTIVE est déjà refusé 403 par le fail-secure #565 —
        // la preuve de révocation se fait donc au niveau base, puis via le 401.)
        $this->assertSame(1, $this->memberTokenCount($member));

        $this->withHeaders($this->bearer($this->supradmin))
            ->deleteJson("/api/admin/institutions/{$institution->id}")->assertStatus(200);
        $this->app['auth']->forgetGuards();

        // R3 : sessions du tenant révoquées → jeton supprimé en base…
        $this->assertSame(0, $this->memberTokenCount($member));

        // …et le jeton révoqué échoue à auth:sanctum (401, en amont du tenant).
        $this->withHeaders(['Authorization' => 'Bearer '.$memberToken])
            ->getJson('/api/user')->assertStatus(401);
    }

    public function test_supradmin_own_session_is_not_revoked(): void
    {
        $institution = Institution::factory()->inactive()->create();
        $headers = $this->bearer($this->supradmin);

        $this->withHeaders($headers)
            ->deleteJson("/api/admin/institutions/{$institution->id}")->assertStatus(200);
        $this->app['auth']->forgetGuards();

        // Le supradmin (institution_id null) n'est pas un membre du tenant supprimé.
        $this->withHeaders($headers)->getJson('/api/user')->assertStatus(200);
    }

    public function test_other_tenant_sessions_are_not_revoked(): void
    {
        // §1.3 multi-tenant : la suppression de A ne touche pas les sessions de B.
        $instA = Institution::factory()->inactive()->create();
        $instB = Institution::factory()->create(['is_active' => true]);
        $memberB = User::factory()->create(['institution_id' => $instB->id, 'role' => 'etudiant']);
        $tokenB = $memberB->createToken('witness')->plainTextToken;

        $this->withHeaders($this->bearer($this->supradmin))
            ->deleteJson("/api/admin/institutions/{$instA->id}")->assertStatus(200);
        $this->app['auth']->forgetGuards();

        // Le jeton du membre de B reste valide.
        $this->assertSame(1, PersonalAccessToken::query()
            ->where('tokenable_type', $memberB->getMorphClass())
            ->where('tokenable_id', $memberB->id)
            ->count());
        $this->withHeaders(['Authorization' => 'Bearer '.$tokenB])
            ->getJson('/api/user')->assertStatus(200);
    }

    public function test_soft_delete_writes_audit_entry(): void
    {
        $institution = Institution::factory()->inactive()->create();

        $this->withHeaders($this->bearer($this->supradmin))
            ->deleteJson("/api/admin/institutions/{$institution->id}")->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'institution.soft_deleted',
            'auditable_id' => $institution->id,
        ]);
    }

    public function test_soft_deleted_institution_can_be_restored(): void
    {
        $institution = Institution::factory()->inactive()->create();
        $institution->delete();
        $this->assertSoftDeleted('institutions', ['id' => $institution->id]);

        $institution->restore();

        $this->assertNull(Institution::find($institution->id)?->deleted_at);
    }
}
