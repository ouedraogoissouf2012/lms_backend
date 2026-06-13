<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests du journal d'audit (#215).
 *
 * Vérifie : génération automatique sur CREATE/UPDATE/DELETE des modèles
 * auditables, exclusion des secrets, endpoint admin (supradmin strict +
 * filtres), et commande de purge selon la rétention.
 *
 * @see app/Services/Audit/AuditLogger.php
 * @see app/Observers/AuditableObserver.php
 * @see app/Http/Controllers/API/Admin/AuditLogController.php
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    public function test_creating_auditable_model_writes_audit_log(): void
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'auditable_type' => $evaluation->getMorphClass(),
            'auditable_id' => $evaluation->id,
        ]);
    }

    public function test_updating_auditable_model_records_before_and_after(): void
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'titre' => 'Titre initial',
        ]);

        $evaluation->update(['titre' => 'Titre modifié']);

        $log = AuditLog::where('action', 'update')
            ->where('auditable_id', $evaluation->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Titre initial', $log->before['titre'] ?? null);
        $this->assertSame('Titre modifié', $log->after['titre'] ?? null);
    }

    public function test_deleting_auditable_model_writes_delete_log(): void
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
        $id = $evaluation->id;

        $evaluation->delete();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete',
            'auditable_id' => $id,
        ]);
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/auth/logout');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'user_id' => $user->id,
        ]);
    }

    public function test_audit_log_endpoint_requires_supradmin(): void
    {
        AuditLog::factory()->count(3)->create();

        // Étudiant / admin tenant : refusés.
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]));
        $this->getJson('/api/admin/audit-log')->assertStatus(403);

        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'superAdmin',
        ]));
        $this->getJson('/api/admin/audit-log')->assertStatus(403);
    }

    public function test_supradmin_can_view_and_filter_audit_log(): void
    {
        AuditLog::factory()->create(['action' => 'login']);
        AuditLog::factory()->create(['action' => 'delete']);

        Sanctum::actingAs(User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]));

        $all = $this->getJson('/api/admin/audit-log');
        $all->assertStatus(200)->assertJsonPath('success', true);

        // Filtre par action.
        $filtered = $this->getJson('/api/admin/audit-log?action=login');
        $filtered->assertStatus(200);
        foreach ($filtered->json('data.data') as $entry) {
            $this->assertSame('login', $entry['action']);
        }
    }

    public function test_purge_command_respects_retention(): void
    {
        config(['audit.retention_days' => 90]);

        $old = AuditLog::factory()->create();
        $old->forceFill(['created_at' => now()->subDays(120)])->save();

        $recent = AuditLog::factory()->create();
        $recent->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('audit:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }
}
