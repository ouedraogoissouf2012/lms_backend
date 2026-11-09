<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lesson;

use App\Enums\Role;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lesson\LessonReadScope;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La borne d'auteur des listes (`LessonReadScope::limitToAuthor`) ne s'applique
 * qu'à un enseignant ; la gestion la précède.
 *
 * Un compte n'a qu'un rôle (`users.role`, une seule colonne) : aucun rôle n'est
 * à la fois de gestion et d'enseignant (premier test). Si un compte l'était
 * malgré tout, la gestion l'emporte : la lecture de la gestion est testée
 * AVANT celle de l'enseignant (second test).
 */
final class LessonReadScopeAuthorTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function roles(): iterable
    {
        foreach (Role::cases() as $role) {
            foreach ($role->aliases() as $alias) {
                yield $alias => [$alias];
            }
        }
    }

    #[DataProvider('roles')]
    public function test_aucun_role_n_est_a_la_fois_de_gestion_et_enseignant(string $role): void
    {
        $compte = new User(['role' => $role]);

        self::assertFalse($compte->isManager() && $compte->isTeacher());
    }

    public function test_un_compte_de_gestion_et_enseignant_garde_la_vue_de_la_gestion(): void
    {
        $compte = new class(['role' => 'coordinateur', 'institution_id' => 7]) extends User
        {
            public function isTeacher(): bool
            {
                return true;
            }
        };
        self::assertTrue($compte->isManager());

        $requete = Lesson::query();
        app(LessonReadScope::class)->limitToAuthor($requete, $compte);

        self::assertSame([], $this->colonnes($requete));
    }

    public function test_un_enseignant_est_borne_a_ses_lecons(): void
    {
        $compte = new User(['role' => 'enseignant', 'institution_id' => 7]);
        $compte->id = 41;

        $requete = Lesson::query();
        app(LessonReadScope::class)->limitToAuthor($requete, $compte);

        self::assertSame(['enseignant_id'], $this->colonnes($requete));
        self::assertSame([41], $requete->getBindings());
    }

    /** Sans identifiant, `where(…, null)` deviendrait `IS NULL` : la liste est fermée. */
    public function test_un_enseignant_sans_identifiant_ne_liste_rien(): void
    {
        $requete = Lesson::query();
        app(LessonReadScope::class)->limitToAuthor($requete, new User(['role' => 'enseignant', 'institution_id' => 7]));

        self::assertSame(['raw'], $this->colonnes($requete));
        self::assertSame('1 = 0', $requete->getQuery()->wheres[0]['sql'] ?? null);
    }

    /**
     * @param  Builder<Lesson>  $requete
     * @return list<string>
     */
    private function colonnes(Builder $requete): array
    {
        return array_values(array_map(
            static fn (array $clause): string => (string) ($clause['column'] ?? $clause['type']),
            $requete->getQuery()->wheres,
        ));
    }
}
