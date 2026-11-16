<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #757 — le coordinateur (et l'admin d'école) recevait une liste VIDE de classes
 * pour une matière sans séance ni miroir, donc un 403 à la création de leçon.
 *
 * ## Le défaut
 *
 * La troisième jambe de `MatiereClassesResolver` (`KlassciMatiereClassesSource`)
 * ne lisait que le tableau de bord ENSEIGNANT, et rendait `[]` à tout autre rôle.
 * Or le gestionnaire a le droit de créer une leçon (`StoreLessonRequest`,
 * `isManager()`), et le front lui montre le bouton.
 *
 * ## Sa source
 *
 * Les classes de l'ÉTABLISSEMENT (`getClasses`, jeton d'établissement), puis le
 * même chemin exact que l'enseignant : le détail de chaque classe, où KLASSCI
 * AFFIRME ses matières. Jamais `me/teacher-dashboard` avec son jeton : le 4xx y
 * serait levé dans `remember()`, et l'appel repartirait à chaque affichage.
 *
 * ## Un pool partiel n'est jamais pris pour complet (#758)
 *
 * Une classe en échec est absente du résultat. Alimenter le miroir avec ce
 * partiel le figerait : la deuxième jambe répondrait non vide, et la troisième ne
 * rejouerait plus jamais. Le partiel est rendu, journalisé, et n'écrit rien.
 *
 * Tout passe par un vrai jeton de requête (`asTenant`).
 */
final class ClassesDuGestionnaireTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MATIERE_KLASSCI = 9200;

    private const JETON = 'jeton-personnel';

    private Institution $ecoleKlassci;

    private Matiere $anglais;

    /** @var list<string> Les appels KLASSCI émis, dans l'ordre. */
    private array $appels = [];

    /** @var list<int> Les classes dont le détail échoue (pool partiel). */
    private array $enEchec = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        $this->ecoleKlassci = $this->ecole(InstitutionMode::Klassci);
        $this->anglais = Matiere::factory()->create([
            'institution_id' => $this->ecoleKlassci->id,
            'klassci_id' => self::MATIERE_KLASSCI,
            'libelle' => 'Anglais',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function gestionnaires(): array
    {
        return ['coordinateur' => ['coordinateur'], "admin d'école" => ['admin']];
    }

    /** @return array<string, array{string}> */
    public static function lecteursDeKlassci(): array
    {
        return ['enseignant' => ['enseignant'], 'coordinateur' => ['coordinateur']];
    }

    #[DataProvider('gestionnaires')]
    public function test_le_gestionnaire_recoit_les_classes_que_klassci_rattache_a_la_matiere(string $role): void
    {
        $b2 = $this->classeKlassci('B2 COM', 1);
        $this->classeKlassci('B3 COM', 2);
        $bts = $this->classeKlassci('BTS Genie Civil', 4);
        $this->simulerKlassci(classesEcole: [1, 2, 4], matieresParClasse: [1 => [self::MATIERE_KLASSCI], 2 => [], 4 => [self::MATIERE_KLASSCI]]);

        $page = $this->page($this->compte($role));

        self::assertSame([$b2->id, $bts->id], array_column($page['classes_concernees'], 'id'), 'Ids LOCAUX, classes portant la matière, et elles seules.');
        self::assertSame(2, DB::table('classe_matiere')->where('matiere_id', $this->anglais->id)->count(), 'Le miroir est alimenté.');
        self::assertContains('classes', $this->appels, "Les classes de l'établissement sont lues.");
        self::assertNotContains('me/teacher-dashboard', $this->appels, "Jamais le tableau de bord enseignant au jeton d'un gestionnaire.");
    }

    public function test_temoin_l_enseignant_passe_toujours_par_son_tableau_de_bord(): void
    {
        $b2 = $this->classeKlassci('B2 COM', 1);
        $this->simulerKlassci(classesEcole: [1], matieresParClasse: [1 => [self::MATIERE_KLASSCI]]);

        $page = $this->page($this->compte('enseignant'));

        self::assertSame([$b2->id], array_column($page['classes_concernees'], 'id'));
        self::assertContains('me/teacher-dashboard', $this->appels);
        self::assertNotContains('classes', $this->appels, "L'enseignant ne lit pas toutes les classes de l'établissement.");
    }

    public function test_temoin_l_eleve_n_interroge_jamais_klassci_pour_les_classes(): void
    {
        $this->classeKlassci('B2 COM', 1);
        $this->simulerKlassci(classesEcole: [1], matieresParClasse: [1 => [self::MATIERE_KLASSCI]]);

        $page = $this->page($this->compte('etudiant'));

        self::assertSame([], $page['classes_concernees']);
        self::assertNotContains('classes', $this->appels);
        self::assertNotContains('details', $this->appels);
    }

    /**
     * Le parcours de l'écran, de bout en bout : la classe que la page rend au
     * coordinateur est acceptée à la création (#1103 n'accepte que les classes
     * proposées par la page). Avant : liste vide, donc pas de classe, donc 403.
     */
    public function test_le_coordinateur_cree_une_lecon_dans_la_classe_rendue_par_la_page(): void
    {
        $this->classeKlassci('B2 COM', 1);
        $this->simulerKlassci(classesEcole: [1], matieresParClasse: [1 => [self::MATIERE_KLASSCI]]);
        $coordinateur = $this->compte('coordinateur');

        $page = $this->page($coordinateur);
        self::assertNotSame([], $page['classes_concernees'], 'La page doit proposer une classe.');
        $classeRendue = $page['classes_concernees'][0]['id'];

        $this->asTenant($coordinateur)->postJson('/api/lessons', [
            'title' => 'Present simple',
            'type' => 'cours',
            'status' => 'draft',
            'classe_id' => $classeRendue,
            'matiere_id' => $page['matiere_id_local'],
        ])->assertCreated()->assertJsonPath('data.classe_id', $classeRendue);
    }

    /**
     * #758 : une classe dont le détail échoue est absente. La liste rendue est
     * celle des classes obtenues, le miroir n'est PAS écrit, et le manque est
     * journalisé. Le tour suivant, KLASSCI complet, rend la liste entière : rien
     * n'a figé le partiel.
     */
    #[DataProvider('lecteursDeKlassci')]
    public function test_un_pool_partiel_n_est_jamais_pris_pour_complet(string $role): void
    {
        $b2 = $this->classeKlassci('B2 COM', 1);
        $bts = $this->classeKlassci('BTS Genie Civil', 4);
        $this->simulerKlassci(classesEcole: [1, 4], matieresParClasse: [1 => [self::MATIERE_KLASSCI], 4 => [self::MATIERE_KLASSCI]]);
        $this->enEchec = [4];
        $compte = $this->compte($role);
        Log::spy();

        $partielle = $this->page($compte);

        self::assertSame([$b2->id], array_column($partielle['classes_concernees'], 'id'), 'Les classes obtenues, jamais une autre.');
        self::assertSame(0, DB::table('classe_matiere')->count(), "Un partiel n'alimente jamais le miroir.");
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $contexte = []): bool => ($contexte['manquantes'] ?? null) === 1
        )->once();

        $this->enEchec = [];
        $complete = $this->page($compte);

        self::assertSame([$b2->id, $bts->id], array_column($complete['classes_concernees'], 'id'), 'Le partiel ne doit pas avoir été figé.');
        self::assertSame(2, DB::table('classe_matiere')->count(), 'Une réponse complète alimente le miroir.');
    }

    /**
     * Témoin, volet autonome : la source est locale (`LocalMatiereDetailsReader`),
     * le coordinateur y voit les classes de la matière, et KLASSCI n'est jamais appelé.
     */
    public function test_temoin_ecole_autonome_le_coordinateur_voit_les_classes_sans_klassci(): void
    {
        $ecole = $this->ecole();
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $classe = $this->classe($ecole, '6e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $this->membre($ecole, 'enseignant'));
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getClasses');
            $mock->shouldNotReceive('requestWithUserToken');
        });

        $page = $this->asTenant($coordinateur)->getJson("/api/lms/matieres/{$maths->id}")->assertOk()->json('data');

        self::assertIsArray($page);
        self::assertSame([$classe->id], array_column($page['classes_concernees'], 'id'));
    }

    // ───────────────────────── fixtures

    private function compte(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->ecoleKlassci->id,
            'role' => $role,
            'klassci_token' => self::JETON,
        ]);
    }

    private function classeKlassci(string $libelle, int $klassciId): Classe
    {
        return Classe::factory()->create(['institution_id' => $this->ecoleKlassci->id, 'klassci_id' => $klassciId, 'libelle' => $libelle]);
    }

    /** @return array<string, mixed> La page de la matière, lue avec le vrai jeton du compte. */
    private function page(User $compte): array
    {
        $page = $this->asTenant($compte)->getJson('/api/lms/matieres/'.self::MATIERE_KLASSCI)->assertOk()->json('data');
        self::assertIsArray($page);

        return $page;
    }

    /**
     * KLASSCI simulé : la matière, sans séance ; les classes de l'établissement ;
     * le tableau de bord enseignant (les mêmes classes) ; le détail de chaque
     * classe, sauf celles de `$enEchec`. Chaque appel est noté dans `$appels`.
     *
     * @param  list<int>  $classesEcole
     * @param  array<int, list<int>>  $matieresParClasse
     */
    private function simulerKlassci(array $classesEcole, array $matieresParClasse): void
    {
        $classes = array_map(static fn (int $id): array => ['id' => $id, 'libelle' => 'Classe '.$id], $classesEcole);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classes, $matieresParClasse): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(function (string $jeton, string $endpoint) use ($classes): array {
                $this->appels[] = $endpoint;

                return match ($endpoint) {
                    'matieres/'.self::MATIERE_KLASSCI => ['data' => ['matiere' => ['id' => self::MATIERE_KLASSCI, 'nom' => 'Anglais'], 'combinaisons' => [], 'enseignants' => []]],
                    'me/teacher-dashboard' => ['data' => ['classes' => $classes]],
                    default => ['data' => []],
                };
            });
            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => []]);
            $mock->shouldReceive('getClasses')->andReturnUsing(function () use ($classes): array {
                $this->appels[] = 'classes';

                return ['success' => true, 'data' => $classes];
            });
            $mock->shouldReceive('fetchManyClassesDetailsWithinTimeout')->andReturnUsing(
                function (array $ids, string $jeton) use ($matieresParClasse): array {
                    $this->appels[] = 'details';
                    self::assertSame(self::JETON, $jeton, 'Le détail est lu au jeton PERSONNEL, comme l\'écran d\'une classe.');
                    $details = [];
                    foreach (array_diff($ids, $this->enEchec) as $id) {
                        $details[$id] = ['data' => [
                            'classe' => ['id' => $id],
                            'matieres' => array_map(static fn (int $m): array => ['id' => $m], $matieresParClasse[$id] ?? []),
                        ]];
                    }

                    return $details;
                }
            );
        });
    }
}
