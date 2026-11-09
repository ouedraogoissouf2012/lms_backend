<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Les LISTES de leçons d'un enseignant ne portent que les siennes (décision de
 * l'utilisateur D72 pour « Mes leçons », D73 pour la page d'une matière) :
 * - `GET /lessons` (« Mes leçons », le compteur du hub, le tableau de bord) et
 *   `GET /lessons/my-courses` ;
 * - la page d'une matière (`GET /lms/matieres/{id}`), dans les deux types d'école.
 *
 * La gestion (coordinateur, admin, superAdmin) lit toutes les leçons de son
 * école. La lecture DIRECTE d'une leçon (détail, chapitres) reste ouverte au
 * personnel : D72 ne porte que sur les listes.
 *
 * Écoles créées depuis KLASSCI et depuis le LMS, jeton porteur réel.
 */
final class ListesDesLeconsDeLEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const JETON_KLASSCI = 'jeton-klassci';

    private Institution $ecole;

    private User $moi;

    private User $collegue;

    private Matiere $matiere;

    /** @var array{brouillon: Lesson, publiee: Lesson} */
    private array $miennes;

    /** @var array{brouillon: Lesson, publiee: Lesson} */
    private array $siennes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function enseignants(): iterable
    {
        foreach (self::ecoles() as $nom => [$mode]) {
            foreach (['enseignant', 'teacher'] as $role) {
                yield "{$role}, {$nom}" => [$mode, $role];
            }
        }
    }

    /** @return iterable<string, array{0: InstitutionMode, 1: string}> */
    public static function gestion(): iterable
    {
        foreach (self::ecoles() as $nom => [$mode]) {
            foreach (['coordinateur', 'coordinator', 'admin', 'administrateur', 'superAdmin'] as $role) {
                yield "{$role}, {$nom}" => [$mode, $role];
            }
        }
    }

    #[DataProvider('enseignants')]
    public function test_mes_lecons_ne_liste_que_les_siennes(InstitutionMode $mode, string $role): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, $role);

        $liste = $this->asTenant($this->moi)->getJson('/api/lessons?per_page=100')->assertOk();
        self::assertSame($this->ids($this->miennes), $this->idsDe($liste->json('data.data')));
        $liste->assertJsonPath('data.total', 2);

        $brouillons = $this->asTenant($this->moi)->getJson('/api/lessons?per_page=100&status=draft')->assertOk();
        self::assertSame([$this->miennes['brouillon']->id], $this->idsDe($brouillons->json('data.data')));

        $mesCours = $this->asTenant($this->moi)->getJson('/api/lessons/my-courses?per_page=100')->assertOk();
        self::assertSame([$this->miennes['publiee']->id], $this->idsDe($mesCours->json('data')));
    }

    #[DataProvider('ecoles')]
    public function test_filtrer_sur_un_collegue_rend_une_liste_vide(InstitutionMode $mode): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, 'enseignant');

        $this->asTenant($this->moi)->getJson("/api/lessons?per_page=100&enseignant_id={$this->collegue->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.data', []);
    }

    /**
     * La borne d'auteur ne remplace pas celle de l'école : une leçon qu'il a
     * écrite dans une AUTRE école (compte déplacé, donnée ancienne) n'est pas
     * listée.
     */
    #[DataProvider('ecoles')]
    public function test_ses_lecons_d_une_autre_ecole_ne_sont_pas_listees(InstitutionMode $mode): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, 'enseignant');
        $ailleurs = Institution::factory()->create(['mode' => $mode]);
        Lesson::factory()->published()->create(['institution_id' => $ailleurs->id, 'enseignant_id' => $this->moi->id]);

        $liste = $this->asTenant($this->moi)->getJson('/api/lessons?per_page=100')->assertOk();
        self::assertSame($this->ids($this->miennes), $this->idsDe($liste->json('data.data')));

        $mesCours = $this->asTenant($this->moi)->getJson('/api/lessons/my-courses?per_page=100')->assertOk();
        self::assertSame([$this->miennes['publiee']->id], $this->idsDe($mesCours->json('data')));
    }

    #[DataProvider('gestion')]
    public function test_la_gestion_liste_toutes_les_lecons_de_son_ecole(InstitutionMode $mode, string $role): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, 'enseignant');
        $gestionnaire = $this->compte($role);

        $liste = $this->asTenant($gestionnaire)->getJson('/api/lessons?per_page=100')->assertOk();
        self::assertSame($this->ids($this->miennes, $this->siennes), $this->idsDe($liste->json('data.data')));

        $filtree = $this->asTenant($gestionnaire)->getJson("/api/lessons?per_page=100&enseignant_id={$this->collegue->id}")->assertOk();
        self::assertSame($this->ids($this->siennes), $this->idsDe($filtree->json('data.data')));
    }

    /** D72 ne porte que sur les listes : la lecture directe reste celle du personnel. */
    #[DataProvider('ecoles')]
    public function test_la_lecture_directe_de_la_lecon_d_un_collegue_reste_ouverte(InstitutionMode $mode): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, 'enseignant');
        $sienne = $this->siennes['brouillon'];

        $this->asTenant($this->moi)->getJson("/api/lessons/{$sienne->id}")->assertOk()->assertJsonPath('data.id', $sienne->id);
        $this->asTenant($this->moi)->getJson("/api/lessons/{$sienne->id}/chapters")->assertOk()->assertJsonPath('success', true);
    }

    #[DataProvider('enseignants')]
    public function test_la_page_d_une_matiere_ne_liste_que_les_siennes(InstitutionMode $mode, string $role): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, $role);

        $page = $this->asTenant($this->moi)->getJson($this->pageMatiere())->assertOk();

        self::assertSame($this->ids($this->miennes), $this->idsDe($page->json('data.lessons')));
    }

    #[DataProvider('gestion')]
    public function test_la_gestion_lit_toutes_les_lecons_de_la_page_d_une_matiere(InstitutionMode $mode, string $role): void
    {
        $this->ecoleAvecDeuxEnseignants($mode, 'enseignant');

        $page = $this->asTenant($this->compte($role))->getJson($this->pageMatiere())->assertOk();

        self::assertSame($this->ids($this->miennes, $this->siennes), $this->idsDe($page->json('data.lessons')));
    }

    private function ecoleAvecDeuxEnseignants(InstitutionMode $mode, string $role): void
    {
        $this->ecole = Institution::factory()->create(['mode' => $mode]);
        $klassci = $mode === InstitutionMode::Klassci;
        $this->moi = $this->compte($role);
        $this->collegue = $this->compte('enseignant');
        $this->matiere = Matiere::factory()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => $klassci ? 4400 + $this->ecole->id : null,
        ]);

        if (! $klassci) {
            // La page d'une matière de l'école créée depuis le LMS exige que
            // l'enseignant y soit désigné formateur ; le collègue l'est aussi.
            $this->programme($this->classe($this->ecole, '6e A'), $this->matiere, $this->moi);
            $this->programme($this->classe($this->ecole, '5e B'), $this->matiere, $this->collegue);
        }

        $this->miennes = $this->deuxLecons($this->moi);
        $this->siennes = $this->deuxLecons($this->collegue);

        if ($klassci) {
            $this->klassciConnaitLaMatiere();
        }
    }

    /** @return array{brouillon: Lesson, publiee: Lesson} */
    private function deuxLecons(User $auteur): array
    {
        $attributs = ['institution_id' => $this->ecole->id, 'matiere_id' => $this->matiere->id, 'enseignant_id' => $auteur->id];

        return [
            'brouillon' => Lesson::factory()->create($attributs + ['status' => 'draft']),
            'publiee' => Lesson::factory()->published()->create($attributs),
        ];
    }

    private function compte(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => $role,
            'klassci_token' => $this->ecole->mode === InstitutionMode::Klassci ? self::JETON_KLASSCI : null,
        ]);
    }

    /** KLASSCI connaît la matière ; tout autre appel répond vide. */
    private function klassciConnaitLaMatiere(): void
    {
        $klassciId = (int) $this->matiere->klassci_id;

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($klassciId): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(
                static fn (string $jeton, string $chemin): array => $chemin === "matieres/{$klassciId}"
                    ? ['data' => [
                        'matiere' => ['id' => $klassciId, 'nom' => 'Matière', 'code' => 'M1'],
                        'combinaisons' => [],
                        'enseignants' => [],
                        'evaluations' => [],
                    ]]
                    : ['data' => []],
            );
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });
    }

    private function pageMatiere(): string
    {
        $id = $this->ecole->mode === InstitutionMode::Klassci ? $this->matiere->klassci_id : $this->matiere->id;

        return "/api/lms/matieres/{$id}";
    }

    /**
     * @param  array{brouillon: Lesson, publiee: Lesson}  ...$groupes
     * @return list<int>
     */
    private function ids(array ...$groupes): array
    {
        $ids = [];
        foreach ($groupes as $groupe) {
            foreach ($groupe as $lecon) {
                $ids[] = (int) $lecon->id;
            }
        }
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function idsDe(mixed $lignes): array
    {
        $ids = array_map(static fn (mixed $ligne): int => (int) (is_array($ligne) ? ($ligne['id'] ?? 0) : 0), (array) $lignes);
        sort($ids);

        return $ids;
    }
}
