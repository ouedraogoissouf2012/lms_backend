<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Models\Institution;
use App\Models\User;
use App\Rules\UniqueEmailInInstitution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #580 — la règle {@see UniqueEmailInInstitution} isolée de HTTP.
 *
 * La règle interroge la base (elle est l'image de l'index `users_email_institution_unique`) :
 * on la teste donc avec `RefreshDatabase` et de vraies lignes, jamais avec un mock de base
 * (PRODUCTION_STANDARDS §5 « Tests »).
 *
 * @see app/Rules/UniqueEmailInInstitution.php
 */
final class UniqueEmailInInstitutionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $schoolA;
    private Institution $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolA = Institution::factory()->create(['slug' => 'rule-uniq-a']);
        $this->schoolB = Institution::factory()->create(['slug' => 'rule-uniq-b']);
    }

    /**
     * Exécute la règle et retourne les messages d'échec collectés (vide = validation réussie).
     *
     * @return list<string>
     */
    private function failures(ValidationRule $rule, mixed $value): array
    {
        $messages = [];

        $rule->validate('email', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }

    private function userInSchool(Institution $school, string $email): User
    {
        return User::factory()->create([
            'institution_id' => $school->id,
            'email' => $email,
            'role' => 'etudiant',
        ]);
    }

    private function actorOf(Institution $school): User
    {
        return User::factory()->create([
            'institution_id' => $school->id,
            'role' => 'coordinateur',
        ]);
    }

    public function test_a_free_email_passes(): void
    {
        $rule = UniqueEmailInInstitution::forCreationBy($this->actorOf($this->schoolA));

        $this->assertSame([], $this->failures($rule, 'libre@example.test'));
    }

    public function test_an_email_held_in_another_institution_passes(): void
    {
        $this->userInSchool($this->schoolB, 'ailleurs@example.test');

        $rule = UniqueEmailInInstitution::forCreationBy($this->actorOf($this->schoolA));

        $this->assertSame([], $this->failures($rule, 'ailleurs@example.test'));
    }

    public function test_an_email_held_by_an_active_account_of_the_institution_fails(): void
    {
        $this->userInSchool($this->schoolA, 'occupe@example.test');

        $rule = UniqueEmailInInstitution::forCreationBy($this->actorOf($this->schoolA));

        $failures = $this->failures($rule, 'occupe@example.test');

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('déjà utilisé', $failures[0]);
        $this->assertStringNotContainsString('supprimé', $failures[0]);
    }

    /**
     * L'index unique compte les lignes soft-deleted (aucun index unique partiel sous MySQL /
     * SQLite) : la règle doit les compter aussi, sinon l'INSERT remonterait en 500.
     */
    public function test_an_email_held_by_a_soft_deleted_account_fails_with_a_distinct_message(): void
    {
        $this->userInSchool($this->schoolA, 'parti@example.test')->delete();

        $rule = UniqueEmailInInstitution::forCreationBy($this->actorOf($this->schoolA));

        $failures = $this->failures($rule, 'parti@example.test');

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('supprimé', $failures[0]);
    }

    public function test_update_ignores_the_target_own_row(): void
    {
        $target = $this->userInSchool($this->schoolA, 'moi@example.test');

        $rule = UniqueEmailInInstitution::forUpdateOf($target);

        $this->assertSame([], $this->failures($rule, 'moi@example.test'));
    }

    public function test_update_is_scoped_to_the_target_institution_not_the_actor(): void
    {
        // Cible dans l'école B, email libre là-bas mais occupé dans l'école A.
        $this->userInSchool($this->schoolA, 'partage@example.test');
        $target = $this->userInSchool($this->schoolB, 'cible@example.test');

        $rule = UniqueEmailInInstitution::forUpdateOf($target);

        $this->assertSame([], $this->failures($rule, 'partage@example.test'));
    }

    public function test_update_still_rejects_a_peer_email_of_the_target_institution(): void
    {
        $this->userInSchool($this->schoolA, 'pair@example.test');
        $target = $this->userInSchool($this->schoolA, 'cible@example.test');

        $rule = UniqueEmailInInstitution::forUpdateOf($target);

        $this->assertCount(1, $this->failures($rule, 'pair@example.test'));
    }

    /**
     * Fail-closed : sans institution cible, la règle refuse au lieu de dégénérer en
     * `WHERE institution_id IS NULL` — un ensemble que l'index unique ne contraint pas.
     */
    public function test_an_unresolved_institution_fails_closed_on_creation(): void
    {
        $platform = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $failures = $this->failures(
            UniqueEmailInInstitution::forCreationBy($platform),
            'orphelin@example.test'
        );

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('établissement', $failures[0]);
    }

    public function test_a_missing_actor_fails_closed(): void
    {
        $this->assertCount(
            1,
            $this->failures(UniqueEmailInInstitution::forCreationBy(null), 'x@example.test')
        );
    }

    public function test_a_missing_target_fails_closed(): void
    {
        $this->assertCount(
            1,
            $this->failures(UniqueEmailInInstitution::forUpdateOf(null), 'x@example.test')
        );
    }

    /**
     * Le format est la responsabilité de la règle `email` : une valeur non exploitable est
     * laissée passer sans requête, pour ne pas produire deux messages pour un seul défaut.
     */
    public function test_a_non_string_value_is_left_to_the_email_rule(): void
    {
        $rule = UniqueEmailInInstitution::forCreationBy($this->actorOf($this->schoolA));

        $this->assertSame([], $this->failures($rule, ['tableau']));
        $this->assertSame([], $this->failures($rule, null));
        $this->assertSame([], $this->failures($rule, ''));
    }
}
