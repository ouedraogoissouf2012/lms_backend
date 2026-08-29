<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #511 — le CRUD cross-tenant des institutions (lecture/écriture/rotation
 * de tokens de TOUS les tenants) doit être réservé au gestionnaire PLATEFORME.
 *
 * Vérifie que seul un `supradmin` strict y accède, et qu'aucun autre rôle —
 * y compris l'admin d'institution `superAdmin` et une variante de casse
 * `'Supradmin'` — ne peut atteindre ces endpoints (défense en profondeur :
 * `role:supradmin` strict + `platform.supradmin`).
 *
 * @see routes/api/admin.php
 * @see app/Http/Middleware/EnsurePlatformSupradmin.php
 */
final class InstitutionRoutesPlatformGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role, ?int $institutionId): void
    {
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institutionId,
            'role'           => $role,
            'email'          => 'u'.uniqid().'@guard511.test',
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPlatformRoleProvider(): array
    {
        return [
            'admin institution'  => ['admin'],
            'coordinateur'       => ['coordinateur'],
            'enseignant'         => ['enseignant'],
            'etudiant'           => ['etudiant'],
            'superAdmin (inst.)' => ['superAdmin'],
            'variante de casse'  => ['Supradmin'],
        ];
    }

    #[DataProvider('nonPlatformRoleProvider')]
    public function test_non_platform_role_cannot_read_institutions(string $role): void
    {
        $institution = Institution::factory()->create();
        $this->actAs($role, $institution->id);

        $this->getJson('/api/admin/institutions')->assertStatus(403);
    }

    #[DataProvider('nonPlatformRoleProvider')]
    public function test_non_platform_role_cannot_delete_institution(string $role): void
    {
        $target = Institution::factory()->create();
        $this->actAs($role, $target->id);

        // Le 403 est émis par le middleware AVANT le contrôleur : la suppression
        // n'a jamais lieu (défense en profondeur, aucun effet de bord cross-tenant).
        $this->deleteJson("/api/admin/institutions/{$target->id}")->assertStatus(403);
    }

    public function test_platform_supradmin_can_read_institutions(): void
    {
        Institution::factory()->create();
        $this->actAs('supradmin', null);

        $this->getJson('/api/admin/institutions')->assertStatus(200);
    }
}
