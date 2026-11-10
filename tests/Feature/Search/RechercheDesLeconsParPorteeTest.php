<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La recherche de leçons (`GET /search`, `GET /search/suggestions`) suit la
 * règle des listes (`LessonReadScope::apply`), dans les deux types d'école :
 * - un apprenant ne trouve que les leçons publiées de SES classes ;
 * - un enseignant, que les siennes ;
 * - la gestion, toutes celles de son école.
 *
 * La recherche texte (titre, description ou contenu) reste groupée : un mot
 * trouvé dans une leçon d'une autre classe ne la fait pas passer la borne. Ce
 * groupement est prouvé par le cas de l'ENSEIGNANT. Pour l'apprenant, Eloquent
 * regroupe déjà les clauses antérieures quand `published()` est appelé
 * (`Builder::callScope`), et sa borne de classe tient même sans la closure.
 * Vrai jeton porteur ; KLASSCI ne répond rien (sources dégradables). La
 * recherche demande 20 résultats par catégorie (5 par défaut) : aucun n'est
 * tronqué.
 */
final class RechercheDesLeconsParPorteeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOT = 'derivee';

    private const CLASSE_A = 7201;

    private const CLASSE_B = 7202;

    private Institution $ecole;

    private Classe $classeA;

    private Classe $classeB;

    private User $prof;

    private User $collegue;

    /** @var array<string, Lesson> */
    private array $lecons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
    }

    #[DataProvider('ecoles')]
    public function test_l_apprenant_ne_trouve_que_les_lecons_publiees_de_sa_classe(InstitutionMode $mode): void
    {
        $this->ecoleAvecLecons($mode);
        $eleve = $this->eleveInscritEnA($mode);

        self::assertSame(['Derivee publiee de A'], $this->titresTrouves($eleve));
        self::assertSame(['Derivee publiee de A'], $this->suggestions($eleve));
    }

    #[DataProvider('ecoles')]
    public function test_un_apprenant_sans_classe_ne_trouve_aucune_lecon(InstitutionMode $mode): void
    {
        $this->ecoleAvecLecons($mode);
        $eleve = User::factory()->student()->create(['institution_id' => $this->ecole->id]);

        self::assertSame([], $this->titresTrouves($eleve));
        self::assertSame([], $this->suggestions($eleve));
    }

    #[DataProvider('ecoles')]
    public function test_l_enseignant_ne_trouve_que_les_siennes(InstitutionMode $mode): void
    {
        $this->ecoleAvecLecons($mode);

        self::assertSame(['Derivee de la collegue'], $this->titresTrouves($this->collegue));
        self::assertSame(['Derivee de la collegue'], $this->suggestions($this->collegue));
    }

    #[DataProvider('ecoles')]
    public function test_la_gestion_trouve_toutes_les_lecons_de_son_ecole(InstitutionMode $mode): void
    {
        $this->ecoleAvecLecons($mode);
        $autre = Institution::factory()->create(['mode' => $mode]);
        Lesson::factory()->published()->create(['institution_id' => $autre->id, 'title' => 'Derivee d une autre ecole']);
        $coordinateur = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'coordinateur']);

        $tous = array_map(static fn (Lesson $l): string => $l->title, $this->lecons);
        sort($tous);

        self::assertSame($tous, $this->titresTrouves($coordinateur));
    }

    private function ecoleAvecLecons(InstitutionMode $mode): void
    {
        $this->ecole = Institution::factory()->create(['mode' => $mode, 'is_active' => true]);
        $klassci = $mode === InstitutionMode::Klassci;
        $this->classeA = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $klassci ? self::CLASSE_A : null]);
        $this->classeB = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $klassci ? self::CLASSE_B : null]);
        $this->prof = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant']);
        $this->collegue = User::factory()->create(['institution_id' => $this->ecole->id, 'role' => 'enseignant']);

        $de = fn (Classe $classe, User $auteur, array $attributs): Lesson => Lesson::factory()->published()->create($attributs + [
            'institution_id' => $this->ecole->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $auteur->id,
            'description' => 'sans le mot',
            'content' => 'sans le mot',
        ]);

        $this->lecons = [
            'publieeA' => $de($this->classeA, $this->prof, ['title' => 'Derivee publiee de A']),
            'brouillonA' => $de($this->classeA, $this->prof, ['title' => 'Derivee en brouillon de A', 'status' => 'draft']),
            // Le mot dans chacune des trois colonnes de la recherche texte, mais
            // dans une AUTRE classe : aucune ne doit passer la borne de classe.
            'titreB' => $de($this->classeB, $this->prof, ['title' => 'Derivee publiee de B']),
            'descriptionB' => $de($this->classeB, $this->prof, ['title' => 'Lecon B une', 'description' => 'une derivee']),
            'contenuB' => $de($this->classeB, $this->prof, ['title' => 'Lecon B deux', 'content' => 'une derivee']),
            'collegue' => $de($this->classeB, $this->collegue, ['title' => 'Derivee de la collegue']),
        ];
    }

    private function eleveInscritEnA(InstitutionMode $mode): User
    {
        $eleve = User::factory()->student()->create([
            'institution_id' => $this->ecole->id,
            'klassci_id' => $mode === InstitutionMode::Klassci ? 920201 : null,
        ]);

        if ($mode === InstitutionMode::Klassci) {
            UserClass::create([
                'user_id' => $eleve->id,
                'klassci_classe_id' => self::CLASSE_A,
                'classe_nom' => 'A',
                'institution_id' => $this->ecole->id,
                'synced_at' => now(),
            ]);
        } else {
            DB::table('classe_etudiant')->insert([
                'classe_id' => $this->classeA->id,
                'user_id' => $eleve->id,
                'statut' => 'actif',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $eleve;
    }

    /** @return list<string> */
    private function titresTrouves(User $compte): array
    {
        $lecons = $this->asTenant($compte)->getJson('/api/search?limit=20&query='.self::MOT)->assertOk()->json('results.lessons');
        self::assertIsArray($lecons, 'La clé results.lessons doit exister.');
        $titres = array_map(static fn (mixed $l): string => is_array($l) ? (string) ($l['title'] ?? '') : '', $lecons);
        sort($titres);

        return $titres;
    }

    /** @return list<string> */
    private function suggestions(User $compte): array
    {
        $suggestions = $this->asTenant($compte)->getJson('/api/search/suggestions?query='.self::MOT)->assertOk()->json('suggestions');
        self::assertIsArray($suggestions, 'La clé suggestions doit exister.');
        $titres = array_values(array_filter($suggestions, static fn (mixed $s): bool => is_string($s) && str_starts_with($s, 'Derivee')));
        sort($titres);

        return $titres;
    }
}
