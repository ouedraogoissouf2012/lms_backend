<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Models\User;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La grille des rôles de la liste des enseignants, en unitaire : la lecture du
 * rôle, l'identité comparée dans son seul espace, et l'échec fermé.
 */
final class TeacherDirectoryVisibilityTest extends TestCase
{
    private const LIGNES = [
        ['id' => 501, 'user_id' => 9001, 'nom' => 'A'],
        ['id' => '9001', 'user_id' => 7777, 'nom' => 'C'],
        'pas une ligne',
    ];

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function roles(): iterable
    {
        yield 'coordinateur' => ['coordinateur', ['A', 'C']];
        yield 'admin' => ['admin', ['A', 'C']];
        yield 'superAdmin' => ['superAdmin', ['A', 'C']];
        yield 'élève' => ['etudiant', []];
        yield 'rôle non reconnu' => ['Etudiant ', []];
    }

    /** @param  list<string>  $noms */
    #[DataProvider('roles')]
    public function test_la_grille_selon_le_role(string $role, array $noms): void
    {
        $lecteur = new User(['role' => $role]);

        self::assertSame($noms, array_column(array_filter((new TeacherDirectoryVisibility)->visibleFor($lecteur, self::LIGNES), 'is_array'), 'nom'));
        self::assertSame($noms !== [], (new TeacherDirectoryVisibility)->readsAnything($lecteur));
    }

    /** @return iterable<string, array{0: mixed, 1: mixed, 2: list<string>}> */
    public static function identites(): iterable
    {
        yield 'espace des enseignants' => [501, null, ['A']];
        yield 'identifiant en chaîne de chiffres' => ['501', null, ['A']];
        // Son klassci_id (9001) est l'id ENSEIGNANT de la ligne C : seule A lui revient.
        yield 'espace des comptes, sans collision' => [null, 9001, ['A']];
        yield 'identifiant enseignant recopié du compte : non lu' => [9001, 9001, ['A']];
        yield 'identifiant nul ou zéro : non lu' => [0, 0, []];
        yield 'sans identifiant : échec fermé' => [null, null, []];
        yield 'identifiant non numérique : échec fermé' => ['abc', '', []];
    }

    /** @param  list<string>  $noms */
    #[DataProvider('identites')]
    public function test_un_enseignant_ne_lit_que_sa_ligne(mixed $enseignantId, mixed $klassciId, array $noms): void
    {
        $lecteur = new User(['role' => 'enseignant']);
        $lecteur->klassci_enseignant_id = $enseignantId;
        $lecteur->klassci_id = $klassciId;

        self::assertSame($noms, array_column((new TeacherDirectoryVisibility)->visibleFor($lecteur, self::LIGNES), 'nom'));
    }
}
