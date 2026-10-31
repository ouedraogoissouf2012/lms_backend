<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Matiere\LocalMatiereDetailsReader;
use App\Services\Matiere\LocalTeacherMatieresReader;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\Matiere\MatiereDetailsReader;
use App\Services\Matiere\MyMatieresQueryService;
use App\Services\Matiere\TeacherMatieresReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * La grille d'accès et la robustesse des deux lectures du lot 1, telles que la
 * revue indépendante les a demandées : rôles de gestion, casse d'un rôle,
 * compte de plateforme, isolation par HTTP, identifiant malformé, panne de
 * base, et l'identifiant de navigation d'une leçon.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class MatieresEcoleIndependanteAccesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MES_MATIERES = '/api/lms/teacher/my-matieres';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ───────────────────────── la grille d'accès

    public function test_le_superAdmin_lit_toute_la_matiere_mais_n_a_aucune_matiere_a_lui(): void
    {
        // Il passe toute garde de rôle d'établissement, mais ne peut pas être
        // désigné formateur (LocalClasseMatiereLinker n'accepte qu'un
        // enseignant) : « mes matières » est vide, la page est entière.
        [$ecole, $maths] = $this->matiereEnseignee();
        $superAdmin = $this->membre($ecole, 'superAdmin');

        $this->asTenant($superAdmin)->getJson(self::MES_MATIERES)->assertOk()->assertExactJson(['success' => true, 'data' => []]);
        $this->asTenant($superAdmin)->getJson($this->page($maths))->assertOk()->assertJsonCount(1, 'data.lessons');
    }

    public function test_une_variante_de_casse_d_un_role_recoit_la_portee_d_apprenant(): void
    {
        // `Enseignant` n'est pas `enseignant` : la portée la moins privilégiée
        // (#941), donc une matière qu'il n'atteint par aucune inscription.
        [$ecole, $maths] = $this->matiereEnseignee();

        $this->asTenant($this->membre($ecole, 'Enseignant'))->getJson($this->page($maths))->assertNotFound();
    }

    public function test_un_compte_de_plateforme_est_arrete_a_la_porte(): void
    {
        [, $maths] = $this->matiereEnseignee();
        $plateforme = User::factory()->create(['institution_id' => null, 'role' => 'supradmin', 'klassci_token' => null]);

        $this->asTenant($plateforme)->getJson($this->page($maths))->assertForbidden()->assertJsonPath('reason', 'no_institution');
        $this->asTenant($plateforme)->getJson(self::MES_MATIERES)->assertForbidden()->assertJsonPath('reason', 'no_institution');
    }

    public function test_un_apprenant_actif_d_une_classe_ne_lit_pas_une_matiere_enseignee_ailleurs(): void
    {
        [$ecole, $maths] = $this->matiereEnseignee();
        $autreClasse = $this->classe($ecole, '4e C');
        $this->programme($autreClasse, $this->matiere($ecole, 'Histoire'), $this->membre($ecole, 'enseignant'));
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $autreClasse, 'actif');

        $this->asTenant($eleve)->getJson($this->page($maths))->assertNotFound();
    }

    public function test_un_apprenant_d_une_AUTRE_ecole_ne_lit_pas_la_matiere(): void
    {
        [, $maths] = $this->matiereEnseignee();
        $ailleurs = $this->ecole();
        $eleve = $this->membre($ailleurs, 'etudiant');
        $this->inscrire($eleve, $this->classe($ailleurs, '6e A'), 'actif');

        $this->asTenant($eleve)->getJson($this->page($maths))->assertNotFound();
    }

    public function test_mes_matieres_reste_dans_l_ecole_de_l_appelant(): void
    {
        [$ecole] = $this->matiereEnseignee();
        $ailleurs = $this->ecole();
        $voisin = $this->membre($ailleurs, 'enseignant');
        $this->programme($this->classe($ailleurs, '6e A'), $this->matiere($ailleurs, 'Matière voisine'), $voisin);
        $moi = User::query()->where('institution_id', $ecole->getKey())->where('role', 'enseignant')->firstOrFail();

        $reponse = $this->asTenant($moi)->getJson(self::MES_MATIERES)->assertOk();

        self::assertSame(['Maths'], array_column((array) $reponse->json('data'), 'nom'));
        self::assertStringNotContainsString('voisine', (string) $reponse->getContent());
    }

    // ───────────────────────── robustesse, deux volets

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => [InstitutionMode::Klassci];
        yield 'ecole independante' => [InstitutionMode::Standalone];
    }

    #[DataProvider('volets')]
    public function test_un_identifiant_non_numerique_est_une_adresse_inconnue(InstitutionMode $mode): void
    {
        // Avant : un TypeError levé avant le `try` du contrôleur, rendu en 500.
        $compte = $this->membre($this->ecole($mode), 'coordinateur');

        $this->asTenant($compte)->getJson('/api/lms/matieres/abc')->assertNotFound();
    }

    #[DataProvider('volets')]
    public function test_une_panne_de_base_est_journalisee_et_rendue_en_500(InstitutionMode $mode): void
    {
        // Une QueryException est une RuntimeException : elle tombait dans le
        // rendu des erreurs KLASSCI, sans aucun journal.
        $compte = $this->membre($this->ecole($mode), 'enseignant');
        // Les sources sont `final` : une doublure qui implémente le port prend
        // la place de la classe que la fabrique résoudra pour ce volet.
        $source = $mode === InstitutionMode::Klassci ? MyMatieresQueryService::class : LocalTeacherMatieresReader::class;
        $this->app->instance($source, new class implements TeacherMatieresReader
        {
            public function getMatieresForUser(User $user): array
            {
                throw new QueryException('sqlite', 'select 1', [], new RuntimeException('panne simulée'));
            }
        });
        Log::spy();

        $this->asTenant($compte)->getJson(self::MES_MATIERES)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Erreur lors du chargement des matières.']);

        Log::shouldHaveReceived('error')->with('Erreur myMatieres', Mockery::type('array'))->once();
    }

    #[DataProvider('volets')]
    public function test_une_panne_de_base_sur_la_page_est_journalisee(InstitutionMode $mode): void
    {
        $compte = $this->membre($this->ecole($mode), 'coordinateur');
        $source = $mode === InstitutionMode::Klassci ? MatiereDetailsQueryService::class : LocalMatiereDetailsReader::class;
        $this->app->instance($source, new class implements MatiereDetailsReader
        {
            public function getDetailsForUser(int $matiereId, User $user): ?array
            {
                throw new QueryException('sqlite', 'select 1', [], new RuntimeException('panne simulée'));
            }
        });
        Log::spy();

        $this->asTenant($compte)->getJson('/api/lms/matieres/1')
            ->assertStatus(500)
            ->assertJsonPath('error', 'Une erreur est survenue.');

        Log::shouldHaveReceived('error')->with('Erreur récupération détails matière', Mockery::type('array'))->once();
    }

    // ───────────────────────── l'identifiant de navigation d'une leçon

    public function test_une_ecole_convertie_ne_recoit_pas_d_identifiant_klassci_pour_naviguer(): void
    {
        // Une école passée de KLASSCI à indépendante garde le `klassci_id` de
        // ses matières. La page matière y lit l'id LOCAL : rendre le
        // `klassci_id` ouvrirait une autre matière, en 200.
        $convertie = $this->ecole();
        $matiere = Matiere::factory()->create(['institution_id' => $convertie->getKey(), 'klassci_id' => 7003]);
        $lecon = Lesson::factory()->published()->create([
            'institution_id' => $convertie->getKey(),
            'classe_id' => $this->classe($convertie, '6e A')->getKey(),
            'matiere_id' => $matiere->getKey(),
        ]);
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $matiereKlassci = Matiere::factory()->create(['institution_id' => $klassci->getKey(), 'klassci_id' => 7004]);
        $leconKlassci = Lesson::factory()->published()->create([
            'institution_id' => $klassci->getKey(),
            'classe_id' => null,
            'matiere_id' => $matiereKlassci->getKey(),
        ]);

        // Les deux écoles dans la même méthode : la source est résolue à
        // l'appel, jamais gardée d'une requête à l'autre.
        $this->asTenant($this->membre($convertie, 'coordinateur'))->getJson('/api/lessons/'.$lecon->getKey())
            ->assertOk()->assertJsonPath('data.matiere_klassci_id', null);
        $this->asTenant($this->membre($klassci, 'coordinateur'))->getJson('/api/lessons/'.$leconKlassci->getKey())
            ->assertOk()->assertJsonPath('data.matiere_klassci_id', 7004);
    }

    // ───────────────────────── harnais

    /**
     * Une matière de 6e A enseignée par un enseignant, avec une leçon publiée.
     *
     * @return array{0: Institution, 1: Matiere}
     */
    private function matiereEnseignee(): array
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $enseignant);
        $this->lecon($classe, $maths, $enseignant, 'published');

        return [$ecole, $maths];
    }

    private function page(Matiere $matiere): string
    {
        return '/api/lms/matieres/'.$matiere->getKey();
    }
}
