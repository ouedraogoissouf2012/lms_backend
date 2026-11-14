<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Catalogue\SourceLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * D72, E4c : dans une école créée depuis le LMS, les fiches classe et matière
 * montrent leurs évaluations, et les compteurs les comptent.
 *
 * Avant : `evaluations_programmees` valait toujours `[]` et les compteurs 0,
 * « provisoirement ». ARRÊT 2 validé (D225) :
 * - une seule forme d'élément, partagée avec les évaluations LMS seules de la
 *   source KLASSCI ; `source: lms_local` côté local seulement (D172) ;
 * - `has_online` et `online_version.id` : sans eux, le front enverrait un id
 *   local sous `klassci_evaluation_id` (D132) ;
 * - l'audience par rôle : l'élève voit sa liste (E2a), l'enseignant SES
 *   évaluations brouillons compris, la gestion toutes ;
 * - les compteurs égalent l'ensemble rendu, sans une requête par matière.
 *
 * Vraie route, vrai jeton (`asTenant`), aucun appel à KLASSCI.
 */
final class PagesClasseEtMatiereMontrentLesEvaluationsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private Classe $sixiemeA;

    private Classe $sixiemeB;

    private Matiere $maths;

    private Matiere $physique;

    private User $auteur;

    private User $collegue;

    private User $eleve;

    /** @var array<string, Evaluation> */
    private array $evaluations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ecole = $this->ecole();
        $this->sixiemeA = $this->classe($this->ecole, '6e A');
        $this->sixiemeB = $this->classe($this->ecole, '6e B');
        $this->maths = $this->matiere($this->ecole, 'Maths');
        $this->physique = $this->matiere($this->ecole, 'Physique');
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->collegue = $this->membre($this->ecole, 'enseignant');
        $this->eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($this->eleve, $this->sixiemeA, 'actif');
        $this->programme($this->sixiemeA, $this->maths, $this->auteur);
        $this->programme($this->sixiemeA, $this->physique, $this->auteur);
        $this->programme($this->sixiemeB, $this->maths, $this->auteur);

        $this->evaluations = [
            'publiee' => $this->evaluation($this->sixiemeA, $this->maths, $this->auteur),
            'brouillon' => $this->evaluation($this->sixiemeA, $this->maths, $this->auteur, ['is_published' => false, 'status' => 'brouillon']),
            'collegue' => $this->evaluation($this->sixiemeA, $this->maths, $this->collegue),
            'autreClasse' => $this->evaluation($this->sixiemeB, $this->maths, $this->auteur),
            'physique' => $this->evaluation($this->sixiemeA, $this->physique, $this->auteur),
        ];
        $this->aucunAppelKlassci();
    }

    // ───────────────────────── fiche classe

    public function test_fiche_classe_l_eleve_voit_les_evaluations_publiees_de_sa_classe(): void
    {
        $reponse = $this->asTenant($this->eleve)->getJson($this->ficheClasse())->assertOk();

        $this->assertMemes(['publiee', 'collegue', 'physique'], $reponse->json('data.evaluations_programmees'));
        $reponse->assertJsonPath('data.statistiques.nombre_evaluations_programmees', 3);
        $this->assertFormeLocale($reponse->json('data.evaluations_programmees'), avecMatiere: true);
    }

    public function test_fiche_classe_l_enseignant_voit_les_siennes_brouillons_compris(): void
    {
        $reponse = $this->asTenant($this->auteur)->getJson($this->ficheClasse())->assertOk();

        $this->assertMemes(['publiee', 'brouillon', 'physique'], $reponse->json('data.evaluations_programmees'));
        $reponse->assertJsonPath('data.statistiques.nombre_evaluations_programmees', 3);
    }

    public function test_fiche_classe_la_gestion_voit_toutes_celles_de_la_classe(): void
    {
        $reponse = $this->asTenant($this->membre($this->ecole, 'coordinateur'))->getJson($this->ficheClasse())->assertOk();

        $this->assertMemes(['publiee', 'brouillon', 'collegue', 'physique'], $reponse->json('data.evaluations_programmees'));
        $reponse->assertJsonPath('data.statistiques.nombre_evaluations_programmees', 4);
    }

    /** C25 sur la fiche : résultats cachés, la copie de l'élève sort sans note. */
    public function test_fiche_classe_la_copie_de_l_eleve_est_masquee_si_les_resultats_sont_caches(): void
    {
        $cachee = $this->evaluations['publiee'];
        $cachee->forceFill(['show_results' => false])->save();
        $this->copie($cachee, $this->eleve);

        $lignes = (array) $this->asTenant($this->eleve)->getJson($this->ficheClasse())->assertOk()->json('data.evaluations_programmees');
        $copie = array_column($lignes, null, 'id')[$cachee->getKey()]['student_submission'] ?? null;

        self::assertIsArray($copie, 'la copie de l eleve a disparu');
        foreach (['score', 'note_sur_20', 'manual_points'] as $champ) {
            self::assertArrayNotHasKey($champ, $copie);
        }
    }

    // ───────────────────────── fiche matière

    public function test_fiche_matiere_l_eleve_voit_celles_de_sa_classe_avec_leur_version_en_ligne(): void
    {
        $reponse = $this->asTenant($this->eleve)->getJson($this->ficheMatiere())->assertOk();

        $this->assertMemes(['publiee', 'collegue'], $reponse->json('data.evaluations_programmees'));
        $reponse->assertJsonPath('data.statistiques.nombre_evaluations', 2);
        $this->assertFormeLocale($reponse->json('data.evaluations_programmees'), avecMatiere: false);
    }

    public function test_fiche_matiere_l_enseignant_voit_les_siennes_et_la_gestion_toutes(): void
    {
        $this->assertMemes(
            ['publiee', 'brouillon', 'autreClasse'],
            $this->asTenant($this->auteur)->getJson($this->ficheMatiere())->assertOk()->json('data.evaluations_programmees'),
        );
        $this->assertMemes(
            ['publiee', 'brouillon', 'collegue', 'autreClasse'],
            $this->asTenant($this->membre($this->ecole, 'coordinateur'))->getJson($this->ficheMatiere())->assertOk()->json('data.evaluations_programmees'),
        );
    }

    // ───────────────────────── compteurs des listes de matières

    public function test_les_listes_de_matieres_comptent_leurs_evaluations_sans_une_requete_par_matiere(): void
    {
        $admin = $this->asTenant($this->membre($this->ecole, 'coordinateur'))->getJson('/api/admin/matieres')->assertOk();
        self::assertSame(['Maths' => 4, 'Physique' => 1], $this->compteurs($admin->json('data.matieres'), 'nom', 'nb_evaluations'));

        $siennes = $this->asTenant($this->auteur)->getJson('/api/lms/teacher/my-matieres')->assertOk();
        self::assertSame(['Maths' => 3, 'Physique' => 1], $this->compteurs($siennes->json('data'), 'nom', 'statistiques.nombre_evaluations'));

        $requetes = $this->requetesDeLaListe();
        foreach (range(1, 4) as $n) {
            $matiere = $this->matiere($this->ecole, 'Option '.$n);
            $this->programme($this->sixiemeA, $matiere, $this->auteur);
            $this->evaluation($this->sixiemeA, $matiere, $this->auteur);
        }
        self::assertSame($requetes, $this->requetesDeLaListe(), 'une requête de plus par matière');
    }

    // ───────────────────────── harnais

    private function ficheClasse(): string
    {
        return '/api/lms/classes/'.$this->sixiemeA->getKey();
    }

    private function ficheMatiere(): string
    {
        return '/api/lms/matieres/'.$this->maths->getKey();
    }

    /** @param  list<string>  $attendues */
    private function assertMemes(array $attendues, mixed $lignes): void
    {
        self::assertIsArray($lignes);
        $ids = array_map(static fn (array $ligne): mixed => $ligne['id'], $lignes);
        sort($ids);
        $voulus = array_map(fn (string $cle): int => (int) $this->evaluations[$cle]->getKey(), $attendues);
        sort($voulus);

        self::assertSame($voulus, $ids);
    }

    private function assertFormeLocale(mixed $lignes, bool $avecMatiere): void
    {
        self::assertIsArray($lignes);
        self::assertNotEmpty($lignes);
        foreach ($lignes as $ligne) {
            self::assertIsArray($ligne);
            self::assertSame(SourceLocale::LMS_LOCAL, $ligne['source'] ?? null);
            self::assertIsInt($ligne['id']);
            self::assertSame($ligne['id'], $ligne['lms_id']);
            self::assertTrue($ligne['has_online']);
            self::assertSame($ligne['id'], $ligne['online_version']['id'] ?? null);
            self::assertSame(60, $ligne['duree_minutes']);
            self::assertArrayHasKey('is_open', $ligne['programmation']['window'] ?? []);
            $avecMatiere
                ? self::assertIsString($ligne['matiere']['nom'] ?? null)
                : self::assertNull($ligne['matiere']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function compteurs(mixed $lignes, string $nom, string $compteur): array
    {
        self::assertIsArray($lignes);
        $parNom = [];
        foreach ($lignes as $ligne) {
            self::assertIsArray($ligne);
            $parNom[(string) $ligne[$nom]] = data_get($ligne, $compteur);
        }
        ksort($parNom);

        return $parNom;
    }

    private function requetesDeLaListe(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asTenant($this->membre($this->ecole, 'coordinateur'))->getJson('/api/admin/matieres')->assertOk();
        $this->asTenant($this->auteur)->getJson('/api/lms/teacher/my-matieres')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(Classe $classe, Matiere $matiere, User $auteur, array $surcharges = []): Evaluation
    {
        // Comme `LocalEvaluationProgramming` l'écrit : ids et libellés locaux, aucune identité KLASSCI.
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'classe_nom' => $classe->libelle,
            'matiere_id' => $matiere->getKey(),
            'matiere_nom' => $matiere->libelle,
            'enseignant_id' => $auteur->getKey(),
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'is_published' => true,
            'is_online' => true,
            'show_results' => true,
            'status' => 'planifiee',
            'date_evaluation' => now()->addDay(),
            'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function copie(Evaluation $evaluation, User $eleve): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => null,
            'attempt' => 1,
            'status' => 'soumis',
            'score' => '80.00',
            'note_sur_20' => '16.00',
            'manual_points' => ['1' => 4],
            'submitted_at' => now(),
        ]);
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getMatieres', 'getClasses', 'requestWithUserToken');
        });
    }
}
