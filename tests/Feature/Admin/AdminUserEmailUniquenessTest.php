<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #580 (P1 de #563) — l'unicité de l'email doit être évaluée PAR INSTITUTION.
 *
 * `CreateUserRequest:39` et `UpdateUserRequest:35` utilisaient `unique:users,email`, qui
 * interroge la table via le query builder brut : ni le global scope `institution` ni le
 * `SoftDeletingScope` ne s'y appliquent. La contrainte était donc GLOBALE alors que le schéma
 * autorise explicitement le même email dans deux écoles (index `users_email_institution_unique`).
 *
 * Bearer token RÉEL (et non `Sanctum::actingAs`) : c'est `ResolveInstitution` qui pose le tenant
 * à partir du porteur, donc seul ce chemin reproduit la production — même choix que
 * {@see AdminUserTenantIsolationTest}.
 *
 * @see app/Rules/UniqueEmailInInstitution.php
 * @see .claude/specs/580-email-unique-per-institution/
 */
final class AdminUserEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Institution $schoolA;
    private Institution $schoolB;
    private User $coordA;
    private User $coordB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->schoolA = Institution::factory()->create(['slug' => 'email-uniq-a']);
        $this->schoolB = Institution::factory()->create(['slug' => 'email-uniq-b']);

        $this->coordA = User::factory()->create([
            'institution_id' => $this->schoolA->id,
            'role' => 'coordinateur',
        ]);
        $this->coordB = User::factory()->create([
            'institution_id' => $this->schoolB->id,
            'role' => 'coordinateur',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('email-uniq-580')->plainTextToken];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Vacataire Partagé',
            'email' => 'vacataire@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'enseignant',
        ], $overrides);
    }

    private function userInSchool(Institution $school, string $email, string $role = 'etudiant'): User
    {
        return User::factory()->create([
            'institution_id' => $school->id,
            'email' => $email,
            'role' => $role,
        ]);
    }

    // ---------------------------------------------------------------- R1 / R6

    /**
     * R1 — cas métier réel : un enseignant vacataire intervient dans deux écoles.
     * R6 — corollaire : plus de « Cet email est déjà utilisé » qui révélait l'existence
     * d'un compte d'un tenant sur lequel l'acteur n'a aucun droit.
     */
    public function test_same_email_is_accepted_in_a_second_institution(): void
    {
        $this->userInSchool($this->schoolA, 'vacataire@example.test', 'enseignant');

        $this->withHeaders($this->bearer($this->coordB))
            ->postJson('/api/users', $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'vacataire@example.test',
            'institution_id' => $this->schoolB->id,
        ]);
    }

    /** R1 — la ligne créée est bien rattachée à l'institution de l'acteur, jamais à l'autre. */
    public function test_created_user_belongs_to_the_actor_institution(): void
    {
        $this->withHeaders($this->bearer($this->coordB))
            ->postJson('/api/users', $this->payload(['email' => 'nouveau@example.test']))
            ->assertStatus(201);

        $created = User::withoutGlobalScope('institution')
            ->where('email', 'nouveau@example.test')
            ->sole();

        $this->assertSame($this->schoolB->id, $created->institution_id);
    }

    // ---------------------------------------------------------------------- R2

    /** R2 — le correctif ne rouvre PAS le doublon intra-institution (Q2 des 15 questions). */
    public function test_duplicate_email_within_the_same_institution_is_still_rejected(): void
    {
        $this->userInSchool($this->schoolA, 'doublon@example.test');

        $this->withHeaders($this->bearer($this->coordA))
            ->postJson('/api/users', $this->payload(['email' => 'doublon@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::withoutGlobalScope('institution')
            ->where('email', 'doublon@example.test')->count());
    }

    /** R2 — en mise à jour aussi : voler l'email d'un pair de la même école reste refusé. */
    public function test_update_to_an_email_held_by_a_peer_of_the_same_institution_is_rejected(): void
    {
        $this->userInSchool($this->schoolA, 'occupe@example.test');
        $target = $this->userInSchool($this->schoolA, 'cible@example.test');

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$target->id}", ['email' => 'occupe@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('cible@example.test', $target->fresh()?->email);
    }

    // ---------------------------------------------------------------------- R3

    /**
     * R3 — régression mesurée : `unique:users,email,` . $userId concaténait le MODÈLE issu du
     * route-model binding. `Model::__toString()` sérialise en JSON, `str_getcsv` découpe ce JSON
     * en paramètres de règle → SQL invalide → 500 (et le nom/email de la cible finissaient dans
     * le message d'erreur SQL journalisé).
     */
    public function test_updating_a_user_with_its_own_unchanged_email_is_accepted(): void
    {
        $target = $this->userInSchool($this->schoolA, 'inchange@example.test');

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Nom Modifié',
                'email' => 'inchange@example.test',
            ])
            ->assertStatus(200);

        $fresh = $target->fresh();
        $this->assertSame('Nom Modifié', $fresh?->name);
        $this->assertSame('inchange@example.test', $fresh?->email);
    }

    /** R3 — et un changement d'email vers une valeur libre de l'école passe. */
    public function test_updating_a_user_to_a_free_email_is_accepted(): void
    {
        $target = $this->userInSchool($this->schoolA, 'avant@example.test');

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$target->id}", ['email' => 'apres@example.test'])
            ->assertStatus(200);

        $this->assertSame('apres@example.test', $target->fresh()?->email);
    }

    /** R1 — en mise à jour, l'institution interrogée est celle de la CIBLE, pas une autre école. */
    public function test_update_to_an_email_held_in_another_institution_is_accepted(): void
    {
        $this->userInSchool($this->schoolB, 'ailleurs@example.test');
        $target = $this->userInSchool($this->schoolA, 'ici@example.test');

        $this->withHeaders($this->bearer($this->coordA))
            ->putJson("/api/users/{$target->id}", ['email' => 'ailleurs@example.test'])
            ->assertStatus(200);

        $this->assertSame('ailleurs@example.test', $target->fresh()?->email);
    }

    // ---------------------------------------------------------------------- R4

    /**
     * R4 — l'index `users_email_institution_unique` COMPTE les lignes soft-deleted (ni MySQL ni
     * SQLite n'ont d'index unique partiel). La validation doit donc les compter elle aussi :
     * l'exclure produirait un `INSERT` en violation de contrainte, soit une 500 au lieu d'une 422.
     * Le refus doit être explicite et actionnable, pas un « déjà utilisé » opaque.
     */
    public function test_email_of_a_soft_deleted_account_is_refused_with_422_not_500(): void
    {
        $deleted = $this->userInSchool($this->schoolA, 'parti@example.test');
        $deleted->delete();
        $this->assertSoftDeleted('users', ['id' => $deleted->id]);

        $response = $this->withHeaders($this->bearer($this->coordA))
            ->postJson('/api/users', $this->payload(['email' => 'parti@example.test']));

        $response->assertStatus(422)->assertJsonValidationErrors('email');

        $message = $response->json('errors.email.0');
        $this->assertIsString($message);
        $this->assertStringContainsString('supprimé', $message);
    }

    /** R4 — l'email d'un compte supprimé d'une AUTRE école n'a rien à bloquer ici. */
    public function test_email_of_a_soft_deleted_account_of_another_institution_does_not_block(): void
    {
        $deleted = $this->userInSchool($this->schoolB, 'partib@example.test');
        $deleted->delete();

        $this->withHeaders($this->bearer($this->coordA))
            ->postJson('/api/users', $this->payload(['email' => 'partib@example.test']))
            ->assertStatus(201);
    }

    // ---------------------------------------------------------------------- R5

    /**
     * R5 — fail-closed. Un `supradmin` plateforme n'a pas d'`institution_id` : la ligne créée
     * naîtrait avec `institution_id = NULL`, un ensemble que l'index unique ne contraint PAS
     * (deux NULL ne sont jamais égaux en SQL). On refuse plutôt que de valider contre du vide.
     */
    public function test_platform_supradmin_without_institution_is_refused_fail_closed(): void
    {
        $platform = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);

        $this->withHeaders($this->bearer($platform))
            ->postJson('/api/users', $this->payload(['email' => 'orphelin@example.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'orphelin@example.test']);
    }
}
