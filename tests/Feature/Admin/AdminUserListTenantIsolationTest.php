<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/admin/users` — isolation multi-tenant.
 *
 * ## Pourquoi un VRAI Bearer token, jamais `Sanctum::actingAs`
 *
 * Le tenant est résolu par `ResolveInstitution` EXCLUSIVEMENT depuis le Bearer
 * token. `actingAs` court-circuite ce middleware : le scope devient no-op et
 * l'isolation cesse d'être observable — le test passerait au vert sans rien
 * prouver. C'est le piège documenté en #524.
 *
 * ## Le cas qui verrouille le fail-open
 *
 * `BelongsToInstitution` est FAIL-OPEN quand aucun tenant n'est résolu : il
 * journalise un warning puis retourne SANS appliquer de `where`
 * (BelongsToInstitution.php:82-92). Un supradmin plateforme a
 * `institution_id = null`, donc aucun tenant : un seul appel à cet endpoint
 * dumperait l'annuaire de TOUS les établissements. Le refus est porté par
 * `ListUsersRequest::authorize()`, et c'est ce test qui l'ancre.
 *
 * @see app/Http/Requests/ListUsersRequest.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class AdminUserListTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->instA = Institution::factory()->create(['slug' => 'users-iso-a']);
        $this->instB = Institution::factory()->create(['slug' => 'users-iso-b']);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('admin-users-iso')->plainTextToken];
    }

    private function coordinateurOf(Institution $institution): User
    {
        return User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'coordinateur',
        ]);
    }

    /** @return list<int> ids des comptes peuplés dans l'institution. */
    private function populate(Institution $institution, string $prefix): array
    {
        $ids = [];
        foreach (['etudiant', 'enseignant', 'coordinateur'] as $i => $role) {
            $ids[] = User::factory()->create([
                'institution_id' => $institution->id,
                'role' => $role,
                'email' => $prefix.$i.'@example.test',
            ])->id;
        }

        return $ids;
    }

    public function test_coordinateur_of_a_sees_no_account_of_b(): void
    {
        $idsA = $this->populate($this->instA, 'a');
        $idsB = $this->populate($this->instB, 'b');
        $coordA = $this->coordinateurOf($this->instA);

        $response = $this->getJson('/api/admin/users?per_page=100', $this->bearer($coordA));

        $response->assertStatus(200);
        $returned = array_column($response->json('data.data'), 'id');

        foreach ($idsB as $idB) {
            self::assertNotContains($idB, $returned, 'Un compte de l\'institution B a fuité vers A.');
        }
        foreach ($idsA as $idA) {
            self::assertContains($idA, $returned);
        }
        // Les compteurs doivent être scopés eux aussi : 3 peuplés + le coordinateur.
        self::assertSame(4, $response->json('meta.counts.total'));
    }

    public function test_coordinateur_of_b_sees_no_account_of_a(): void
    {
        // Sens symétrique exigé par PRODUCTION_STANDARDS §1.3 (2 institutions).
        $idsA = $this->populate($this->instA, 'a');
        $idsB = $this->populate($this->instB, 'b');
        $coordB = $this->coordinateurOf($this->instB);

        $response = $this->getJson('/api/admin/users?per_page=100', $this->bearer($coordB));

        $response->assertStatus(200);
        $returned = array_column($response->json('data.data'), 'id');

        foreach ($idsA as $idA) {
            self::assertNotContains($idA, $returned, 'Un compte de l\'institution A a fuité vers B.');
        }
        foreach ($idsB as $idB) {
            self::assertContains($idB, $returned);
        }
        self::assertSame(4, $response->json('meta.counts.total'));
    }

    public function test_platform_supradmin_without_institution_is_refused(): void
    {
        $this->populate($this->instA, 'a');
        $this->populate($this->instB, 'b');

        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);

        // 403 et NON 200-avec-tout : sans tenant résolu, le global scope ne
        // filtre rien. Laisser passer cette requête livrerait l'annuaire complet
        // de la plateforme en une fois.
        $this->getJson('/api/admin/users', $this->bearer($supradmin))
            ->assertStatus(403);
    }
}
