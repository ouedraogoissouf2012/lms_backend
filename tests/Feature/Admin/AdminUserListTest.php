<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/admin/users` — contrat de la liste des comptes LMS.
 *
 * Couvre l'autorisation, la projection exposée, la pagination et la validation
 * des paramètres. L'isolation multi-tenant a son propre fichier
 * ({@see AdminUserListTenantIsolationTest}), car elle exige un vrai Bearer token.
 *
 * @see app/Http/Controllers/API/AdminController.php
 * @see app/Http/Presenters/UserListPresenter.php
 */
final class AdminUserListTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'admin-users-list']);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('admin-users-list')->plainTextToken];
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_enseignant_is_forbidden(): void
    {
        $this->getJson('/api/admin/users', $this->bearer($this->userWithRole('enseignant')))
            ->assertStatus(403);
    }

    public function test_etudiant_is_forbidden(): void
    {
        $this->getJson('/api/admin/users', $this->bearer($this->userWithRole('etudiant')))
            ->assertStatus(403);
    }

    public function test_response_exposes_only_the_allow_listed_fields(): void
    {
        $coordinateur = $this->userWithRole('coordinateur');
        $this->userWithRole('etudiant');

        $response = $this->getJson('/api/admin/users', $this->bearer($coordinateur));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'data' => [['id', 'name', 'email', 'role', 'klassci_id', 'created_at']],
                    'current_page', 'per_page', 'total', 'last_page',
                ],
                'meta' => ['counts' => ['total', 'etudiants', 'enseignants', 'administration']],
            ]);

        // Rien de ce qui touche au secret, au lien tenant ou au cache KLASSCI ne
        // doit transiter : sérialiser le modèle les exposerait tous d'un coup.
        foreach ([
            'password', 'remember_token', 'klassci_token_encrypted', 'klassci_tenant_url',
            'klassci_role', 'klassci_enseignant_id', 'klassci_data', 'institution_id',
            'last_klassci_sync', 'deleted_at', 'institution',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                '"'.$forbidden.'"',
                (string) $response->getContent(),
                "Le champ {$forbidden} ne doit pas être exposé."
            );
        }
    }

    public function test_pagination_defaults_to_25_and_never_repeats_a_row(): void
    {
        $coordinateur = $this->userWithRole('coordinateur');
        // Même `name` pour tous : sans départage par `id`, l'ordre serait instable
        // entre deux pages et une ligne pourrait se répéter ou disparaître.
        User::factory()->count(29)->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
            'name' => 'Homonyme',
        ]);

        $first = $this->getJson('/api/admin/users', $this->bearer($coordinateur));
        $first->assertStatus(200)
            ->assertJsonPath('data.per_page', 25)
            ->assertJsonPath('data.total', 30)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(25, 'data.data');

        $second = $this->getJson('/api/admin/users?page=2', $this->bearer($coordinateur));
        $second->assertStatus(200)->assertJsonCount(5, 'data.data');

        $ids = array_merge(
            array_column($first->json('data.data'), 'id'),
            array_column($second->json('data.data'), 'id'),
        );
        self::assertCount(30, array_unique($ids), 'Une ligne apparaît sur les deux pages.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'per_page au-dessus du plafond' => ['per_page=101'],
            'page nulle' => ['page=0'],
            'tri hors allow-list' => ['sort=password'],
            'direction inconnue' => ['direction=up'],
            'role inexistant' => ['role=hacker'],
            'recherche trop courte' => ['q=a'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueryProvider')]
    public function test_invalid_query_parameters_are_refused(string $query): void
    {
        $this->getJson('/api/admin/users?'.$query, $this->bearer($this->userWithRole('coordinateur')))
            ->assertStatus(422);
    }

    public function test_soft_deleted_account_is_absent_from_list_and_counts(): void
    {
        $coordinateur = $this->userWithRole('coordinateur');
        $supprime = $this->userWithRole('etudiant');
        $supprime->delete();

        $response = $this->getJson('/api/admin/users?with_trashed=1', $this->bearer($coordinateur));

        $response->assertStatus(200);
        self::assertNotContains($supprime->id, array_column($response->json('data.data'), 'id'));
        // `with_trashed` n'est pas un paramètre supporté : il ne ressuscite rien.
        self::assertSame(1, $response->json('data.total'));
        self::assertSame(1, $response->json('meta.counts.total'));
    }

    public function test_counts_place_coordinateur_in_administration(): void
    {
        $coordinateur = $this->userWithRole('coordinateur');
        $this->userWithRole('etudiant');
        $this->userWithRole('enseignant');
        $this->userWithRole('superAdmin');

        $counts = $this->getJson('/api/admin/users', $this->bearer($coordinateur))
            ->assertStatus(200)
            ->json('meta.counts');

        self::assertSame(4, $counts['total']);
        self::assertSame(1, $counts['etudiants']);
        self::assertSame(1, $counts['enseignants']);
        // Le coordinateur lui-même + le superAdmin.
        self::assertSame(2, $counts['administration']);
        self::assertSame(
            $counts['total'],
            $counts['etudiants'] + $counts['enseignants'] + $counts['administration'],
        );
    }
}
