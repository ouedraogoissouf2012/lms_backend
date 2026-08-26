<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #553 — GET /api/admin/statistics
 */
final class AdminStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_admin_receives_fresh_tenant_statistics(): void
    {
        $institution = Institution::factory()->create(['is_active' => true]);
        $admin = User::factory()->for($institution)->create([
            'role' => 'admin',
            'last_klassci_sync' => now(),
        ]);
        User::factory()->for($institution)->create(['role' => 'enseignant']);
        User::factory()->for($institution)->count(2)->create(['role' => 'etudiant']);
        Classe::factory()->for($institution)->create(['filiere_id' => 11, 'niveau_id' => 3]);

        $response = $this->withToken($admin->createToken('553')->plainTextToken)
            ->getJson('/api/admin/statistics');

        $response->assertOk();
        self::assertSame(['success', 'data'], array_keys($response->json()));
        $response->assertJsonPath('data.nb_enseignants', 1)
            ->assertJsonPath('data.nb_etudiants', 2)
            ->assertJsonPath('data.nb_classes_actives', 1)
            ->assertJsonPath('data.nb_filieres', 1)
            ->assertJsonPath('data.nb_niveaux', 1)
            ->assertJsonPath('data.taux_presence', 0);
    }

    public function test_counts_do_not_leak_across_tenants(): void
    {
        $ours = Institution::factory()->create(['is_active' => true]);
        $theirs = Institution::factory()->create(['is_active' => true]);
        $admin = User::factory()->for($ours)->create([
            'role' => 'coordinateur',
            'last_klassci_sync' => now(),
        ]);
        User::factory()->for($ours)->create(['role' => 'enseignant']);
        User::factory()->for($theirs)->count(5)->create(['role' => 'enseignant']);

        $this->withToken($admin->createToken('553')->plainTextToken)
            ->getJson('/api/admin/statistics')
            ->assertOk()
            ->assertJsonPath('data.nb_enseignants', 1);
    }

    public function test_student_is_forbidden(): void
    {
        $institution = Institution::factory()->create(['is_active' => true]);
        $student = User::factory()->for($institution)->create([
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        $this->withToken($student->createToken('553')->plainTextToken)
            ->getJson('/api/admin/statistics')
            ->assertStatus(403);
    }
}
