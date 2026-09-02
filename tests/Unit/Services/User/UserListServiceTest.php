<?php

declare(strict_types=1);

namespace Tests\Unit\Services\User;

use App\Models\Institution;
use App\Models\User;
use App\Services\User\UserListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `UserListService` — recherche et filtrage.
 *
 * Deux invariants qui ne se voient pas à l'œil nu :
 *
 *  1. **Jokers LIKE neutralisés.** `%` et `_` sont des métacaractères SQL. Sans
 *     échappement, saisir `%` dans la recherche ramène tout l'annuaire, et `_`
 *     devient un oracle permettant de deviner une adresse caractère par caractère.
 *  2. **Alias de rôle.** La colonne `users.role` contient indifféremment
 *     `etudiant`, `student` ou `étudiant` selon l'époque du sync KLASSCI
 *     (cf. Role::aliases()). Filtrer sur la seule valeur canonique masquerait
 *     une partie des comptes.
 *
 * @see app/Services/User/UserListService.php
 */
final class UserListServiceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'user-list-svc']);
        $this->actor = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
            'email' => 'actor@example.test',
            'name' => 'Actor',
        ]);
    }

    private function service(): UserListService
    {
        return new UserListService();
    }

    private function makeUser(string $email, string $role = 'etudiant', string $name = 'Compte'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'email' => $email,
            'name' => $name,
        ]);
    }

    public function test_underscore_is_treated_as_a_literal_character(): void
    {
        $exact = $this->makeUser('a_b@example.test');
        $this->makeUser('axb@example.test');

        $page = $this->service()->paginate($this->actor, ['q' => 'a_b']);

        // Sans `ESCAPE`, `_` matcherait n'importe quel caractère et ramènerait
        // aussi `axb@example.test`.
        self::assertSame([$exact->id], array_map(static fn (User $u): int => $u->id, $page->items()));
    }

    public function test_percent_does_not_return_the_whole_directory(): void
    {
        $this->makeUser('un@example.test');
        $this->makeUser('deux@example.test');

        $page = $this->service()->paginate($this->actor, ['q' => '%%']);

        // `%` littéral : aucune adresse ne le contient réellement.
        self::assertSame(0, $page->total());
    }

    public function test_role_filter_matches_every_known_alias(): void
    {
        $fr = $this->makeUser('fr@example.test', 'etudiant');
        $en = $this->makeUser('en@example.test', 'student');
        $accent = $this->makeUser('accent@example.test', 'étudiant');
        $this->makeUser('prof@example.test', 'enseignant');

        $page = $this->service()->paginate($this->actor, ['role' => 'etudiant', 'per_page' => 100]);

        $ids = array_map(static fn (User $u): int => $u->id, $page->items());
        sort($ids);
        $expected = [$fr->id, $en->id, $accent->id];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function test_counts_group_aliases_under_the_same_family(): void
    {
        $this->makeUser('fr@example.test', 'etudiant');
        $this->makeUser('en@example.test', 'student');
        $this->makeUser('prof@example.test', 'teacher');

        $counts = $this->service()->counts($this->actor);

        self::assertSame(2, $counts['etudiants']);
        self::assertSame(1, $counts['enseignants']);
        // L'acteur coordinateur.
        self::assertSame(1, $counts['administration']);
        self::assertSame(4, $counts['total']);
    }
}
