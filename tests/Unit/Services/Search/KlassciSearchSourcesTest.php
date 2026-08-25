<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Search\KlassciSearchSources;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Sources KLASSCI de la recherche globale (#505) — lecture des payloads.
 *
 * Aucune base : ces deux sources ne consultent que le proxy. Ce qui est
 * verrouillé ici, c'est la LECTURE d'une réponse tierce non typée — le terrain
 * où un « 0 résultat » silencieux se confond avec une panne.
 */
#[CoversClass(KlassciSearchSources::class)]
final class KlassciSearchSourcesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------ forme du payload

    public function test_the_wrapped_collection_is_unwrapped(): void
    {
        $classes = $this->sources(['success' => true, 'data' => [$this->classe()]])
            ->searchClasses('math', $this->staff(), 5);

        self::assertCount(1, $classes);
        self::assertSame('Mathématiques L1', $classes[0]['title']);
        self::assertSame('/admin/classes/12', $classes[0]['url']);
    }

    public function test_a_bare_record_list_is_also_accepted(): void
    {
        $classes = $this->sources([$this->classe()])->searchClasses('math', $this->staff(), 5);

        self::assertCount(1, $classes);
    }

    public function test_a_response_carrying_no_record_list_is_refused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('classes');

        $this->sources(['message' => 'Service indisponible'])->searchClasses('math', $this->staff(), 5);
    }

    public function test_an_envelope_whose_data_is_not_a_list_is_refused(): void
    {
        // `{data: {…}}` : tous les niveaux sont des tableaux, donc un simple
        // contrôle « chaque valeur est un array » laisserait passer, pour ne
        // produire qu'un « 0 résultat » trompeur.
        $this->expectException(UnexpectedValueException::class);

        $this->sources(['data' => ['id' => 12, 'name' => 'Mathématiques L1']])
            ->searchClasses('math', $this->staff(), 5);
    }

    public function test_an_empty_collection_is_not_an_error(): void
    {
        self::assertSame([], $this->sources(['success' => true, 'data' => []])->searchClasses('math', $this->staff(), 5));
    }

    // ---------------------------------------------------- lecture des champs

    public function test_a_structured_field_never_leaks_as_the_string_array(): void
    {
        $classes = $this->sources([[
            'id' => 12,
            'name' => ['fr' => 'Mathématiques'],
            'filiere' => ['name' => 'Informatique'],
            'niveau' => 'Licence 1',        // scalaire là où un objet est attendu
        ]])->searchClasses('informatique', $this->staff(), 5);

        self::assertCount(1, $classes, 'La correspondance porte sur la filière.');
        self::assertSame('', $classes[0]['title'], 'Un champ structuré vaut chaîne vide, jamais « Array ».');
        self::assertSame('Informatique - ', $classes[0]['description']);
    }

    public function test_a_missing_matiere_code_falls_back_to_na_but_an_empty_one_does_not(): void
    {
        $sources = $this->sources(matieres: [
            ['id' => 1, 'nom' => 'Analyse', 'code' => null],
            ['id' => 2, 'nom' => 'Algèbre', 'code' => ''],
        ]);

        $matieres = $sources->searchMatieres('al', $this->staff(), 5);

        self::assertSame('Code: N/A', $matieres[0]['description'], 'Absent ou nul → N/A.');
        self::assertSame('Code: ', $matieres[1]['description'], 'Chaîne vide → comportement d’origine préservé.');
    }

    public function test_the_query_is_matched_case_insensitively_on_every_field(): void
    {
        $sources = $this->sources(matieres: [
            ['id' => 1, 'nom' => 'Analyse', 'code' => 'MAT101'],
            ['id' => 2, 'nom' => 'Histoire', 'code' => 'HIS201'],
        ]);

        $matieres = $sources->searchMatieres('mat', $this->staff(), 5);

        self::assertCount(1, $matieres, 'Le code doit être consulté, pas seulement le nom.');
        self::assertSame('Analyse', $matieres[0]['title']);
    }

    public function test_the_limit_is_honoured(): void
    {
        $sources = $this->sources(matieres: [
            ['id' => 1, 'nom' => 'Maths A', 'code' => 'A'],
            ['id' => 2, 'nom' => 'Maths B', 'code' => 'B'],
            ['id' => 3, 'nom' => 'Maths C', 'code' => 'C'],
        ]);

        self::assertCount(2, $sources->searchMatieres('maths', $this->staff(), 2));
    }

    // ----------------------------------------------------------- garde rôle

    public function test_a_student_never_reaches_klassci(): void
    {
        $klassci = Mockery::mock(KlassciProxyService::class);
        $klassci->shouldNotReceive('getClasses');
        $klassci->shouldNotReceive('getMatieres');

        $sources = new KlassciSearchSources($klassci);
        $student = $this->user(isStaff: false);

        self::assertSame([], $sources->searchClasses('math', $student, 5));
        self::assertSame([], $sources->searchMatieres('math', $student, 5));
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  array<mixed>|null  $classes
     * @param  array<mixed>|null  $matieres
     */
    private function sources(?array $classes = null, ?array $matieres = null): KlassciSearchSources
    {
        /** @var MockInterface&KlassciProxyService $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);
        $klassci->shouldReceive('getClasses')->andReturn($classes ?? []);
        $klassci->shouldReceive('getMatieres')->andReturn($matieres ?? []);

        return new KlassciSearchSources($klassci);
    }

    /**
     * @return array<string, mixed>
     */
    private function classe(): array
    {
        return [
            'id' => 12,
            'name' => 'Mathématiques L1',
            'filiere' => ['name' => 'Informatique'],
            'niveau' => ['name' => 'Licence 1'],
        ];
    }

    private function staff(): User
    {
        return $this->user(isStaff: true);
    }

    /**
     * Utilisateur NON persisté : seul le rôle compte pour ces deux gardes.
     */
    private function user(bool $isStaff): User
    {
        $user = new User();
        $user->role = $isStaff ? 'coordinateur' : 'etudiant';

        return $user;
    }
}
