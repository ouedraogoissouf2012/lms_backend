<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\ClasseRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1676 : `GET /api/lms/classes/{classe}/resultats`, sur un jeu de notes dont le classement est
 * connu d'avance. Maths (coefficient 1) et Physique (coefficient 3) dans la classe :
 *
 * | élève | Maths | Physique | moyenne générale          | rang |
 * |-------|-------|----------|---------------------------|------|
 * | Awa   | 10    | 16       | (10 + 48) / 4 = 14,5      | 1    |
 * | Binta | 18    | 13       | (18 + 39) / 4 = 14,25     | 3    |
 * | Coffi | 14,5  | 14,5     | 14,5                      | 1    |
 * | Dédé  | —     | —        | null                      | null |
 *
 * Plus : une évaluation sans aucune note, une évaluation aux résultats CACHÉS (hors bilan), une
 * matière au programme sans évaluation. Vraie route, vrai jeton (`asTenant`).
 */
final class BilanDeClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/classes/{classe}/resultats';

    /** L'identité KLASSCI d'une classe : loin des ids locaux, qui ne repartent pas de 1 sous MySQL. */
    public const KLASSCI = 900077;

    private Institution $ecole;

    private Classe $classe;

    /** @var array<string, User> */
    private array $eleves = [];

    private Evaluation $sansNote;

    private Matiere $chimie;

    public function test_moyennes_rangs_ex_aequo_et_eleve_non_classe(): void
    {
        $this->jeuDeNotes(['seuil_reussite' => 14.4]);

        $reponse = $this->bilan($this->membre($this->ecole, 'coordinateur'))->assertOk();

        self::assertSame([
            ['Awa', 14.5, 1],
            ['Binta', 14.25, 3],
            ['Coffi', 14.5, 1],
            ['Dédé', null, null],
        ], $this->classement($reponse));
        $reponse->assertJsonPath('data.source', 'lms')
            ->assertJsonPath('data.seuil_reussite', 14.4)
            ->assertJsonPath('data.statistiques', ['effectif' => 4, 'classes' => 3, 'moyenne_classe' => 14.42, 'taux_reussite' => 66.67]);
    }

    public function test_la_couverture_nomme_les_trous_et_les_notes_cachees_ne_comptent_pas(): void
    {
        $this->jeuDeNotes();

        $reponse = $this->bilan($this->membre($this->ecole, 'coordinateur'))->assertOk();

        $reponse->assertJsonPath('data.couverture.matieres_sans_evaluation', [['matiere_id' => $this->chimie->id, 'libelle' => 'Chimie']])
            ->assertJsonPath('data.couverture.evaluations_sans_note', [$this->sansNote->id])
            ->assertJsonPath('data.eleves.0.notes_manquantes', [$this->sansNote->id]);
        self::assertSame(['Maths 1', 'Physique 1', 'Maths 2'], array_column((array) $reponse->json('data.evaluations'), 'titre'), 'L\'évaluation aux résultats cachés n\'est pas une colonne.');
        self::assertSame([['matiere_id' => $this->matiereDe('Maths 1'), 'moyenne' => 10], ['matiere_id' => $this->matiereDe('Physique 1'), 'moyenne' => 16]], $reponse->json('data.eleves.0.matieres'));
    }

    public function test_la_periode_borne_les_evaluations(): void
    {
        $this->jeuDeNotes();
        Evaluation::query()->whereKey($this->sansNote->id)->update(['date_evaluation' => now()->subMonths(2)]);

        $reponse = $this->bilan($this->membre($this->ecole, 'coordinateur'), '?du='.now()->subDays(20)->toDateString().'&au='.now()->toDateString())->assertOk();

        self::assertSame(['Maths 1', 'Physique 1'], array_column((array) $reponse->json('data.evaluations'), 'titre'));
        $reponse->assertJsonPath('data.couverture.evaluations_sans_note', []);
    }

    public function test_l_enseignant_lit_la_classe_qu_il_enseigne_et_seulement_elle(): void
    {
        $this->jeuDeNotes();
        $formateur = $this->membre($this->ecole, 'enseignant');
        DB::table('classe_matiere')->where('classe_id', $this->classe->id)->where('matiere_id', $this->chimie->id)->update(['enseignant_id' => $formateur->id]);

        $this->bilan($formateur)->assertOk()->assertJsonPath('data.statistiques.classes', 3);
        $this->bilan($this->membre($this->ecole, 'enseignant'))->assertForbidden()->assertJsonPath('reason', 'class_not_taught');
    }

    public function test_l_eleve_ne_lit_que_sa_ligne_et_son_rang_seulement_si_l_ecole_l_autorise(): void
    {
        $this->jeuDeNotes();

        $sansRang = $this->bilan($this->eleves['Binta'])->assertOk();
        self::assertSame(['Binta'], array_column((array) $sansRang->json('data.eleves'), 'nom'));
        self::assertArrayNotHasKey('rang', (array) $sansRang->json('data.eleves.0'));
        $sansRang->assertJsonMissingPath('data.statistiques')->assertJsonMissingPath('data.couverture');

        $this->ecole->forceFill(['settings' => ['rang_visible_eleve' => true]])->save();
        $this->bilan($this->eleves['Binta'])->assertOk()->assertJsonPath('data.eleves.0.rang', 3);
    }

    public function test_hors_de_la_classe_ou_de_l_ecole_rien_n_est_lu(): void
    {
        $this->jeuDeNotes();
        $etranger = $this->membre($this->ecole, 'etudiant');
        $autreEcole = $this->membre($this->ecole(), 'coordinateur');

        $this->bilan($etranger)->assertForbidden()->assertJsonPath('reason', 'not_in_class');
        $this->bilan($autreEcole)->assertNotFound()->assertJsonPath('reason', 'class_out_of_scope');
    }

    public function test_ecole_klassci_le_roster_de_klassci_fait_foi(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::KLASSCI, 'libelle' => 'BTS 1']);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 9001])->save();
        $this->roster([['id' => 9001, 'nom' => 'Kouassi', 'prenom' => 'Ama', 'email' => 'inconnu@ecole.test'], ['id' => 9002, 'nom' => 'Sans', 'prenom' => 'Compte']]);
        $devoir = $this->evaluation($ecole, null, 'Devoir KLASSCI', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI]);
        $this->note($devoir, $eleve, '12.00');
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->disableKlassciMiddleware();

        // L'identifiant de l'espace SOURCE, comme `/classes/{classeId}/etudiants` : l'identité KLASSCI.
        $reponse = $this->bilan($coordinateur, '', self::KLASSCI)->assertOk();

        $reponse->assertJsonPath('data.source', 'lms')->assertJsonPath('data.classe.id', $classe->id);
        self::assertSame([[$eleve->id, 12, 1], [null, null, null]], array_map(
            static fn (array $l): array => [$l['compte_id'], $l['moyenne_generale'], $l['rang']],
            (array) $reponse->json('data.eleves'),
        ));
    }

    /** École KLASSCI : l'id LOCAL n'est pas l'espace de la route, et une identité sans miroir n'est rien. */
    public function test_ecole_klassci_seule_l_identite_klassci_d_un_miroir_designe_la_classe(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::KLASSCI]);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->roster([]);
        $this->disableKlassciMiddleware();
        self::assertNotSame(self::KLASSCI, $classe->id, 'Le test exige deux espaces distincts.');

        $this->bilan($coordinateur, '', $classe->id)->assertNotFound()->assertJsonPath('reason', 'class_out_of_scope');
        $this->bilan($coordinateur, '', self::KLASSCI + 1)->assertNotFound()->assertJsonPath('reason', 'class_out_of_scope');
        $this->bilan($coordinateur, '', self::KLASSCI)->assertOk();
    }

    /** @param  array<string, mixed>  $reglages */
    private function jeuDeNotes(array $reglages = []): void
    {
        $this->ecole = $this->ecole();
        $this->ecole->forceFill(['settings' => $reglages])->save();
        $this->classe = $this->classe($this->ecole, '6e A');
        $maths = $this->auProgramme('Maths', 1);
        $physique = $this->auProgramme('Physique', 3);
        $this->chimie = $this->auProgramme('Chimie', 2);
        foreach (['Awa', 'Binta', 'Coffi', 'Dédé'] as $nom) {
            $this->eleves[$nom] = $this->membre($this->ecole, 'etudiant');
            $this->eleves[$nom]->forceFill(['name' => $nom])->save();
            $this->inscrire($this->eleves[$nom], $this->classe, 'actif');
        }
        $m1 = $this->evaluation($this->ecole, $maths, 'Maths 1');
        $p1 = $this->evaluation($this->ecole, $physique, 'Physique 1');
        $this->sansNote = $this->evaluation($this->ecole, $maths, 'Maths 2');
        $cachee = $this->evaluation($this->ecole, $physique, 'Cachée', ['show_results' => false]);
        foreach (['Awa' => ['10.00', '16.00'], 'Binta' => ['18.00', '13.00'], 'Coffi' => ['14.50', '14.50']] as $nom => [$enMaths, $enPhysique]) {
            $this->note($m1, $this->eleves[$nom], $enMaths);
            $this->note($p1, $this->eleves[$nom], $enPhysique);
            $this->note($cachee, $this->eleves[$nom], '0.00');
        }
    }

    private function auProgramme(string $libelle, int $coefficient): Matiere
    {
        $matiere = $this->matiere($this->ecole, $libelle);
        DB::table('classe_matiere')->insert([
            'classe_id' => $this->classe->id, 'matiere_id' => $matiere->id, 'institution_id' => $this->ecole->id,
            'coefficient' => $coefficient, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $matiere;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(Institution $ecole, ?Matiere $matiere, string $titre, array $surcharges = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $ecole->id,
            'classe_id' => isset($this->classe) && $this->classe->institution_id === $ecole->id ? $this->classe->id : null,
            'matiere_id' => $matiere?->id,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null,
            'titre' => $titre, 'coefficient' => '1.00', 'is_published' => true, 'show_results' => true,
            'status' => 'terminee', 'date_evaluation' => now()->subDays(10)->addDays(Evaluation::query()->count()), 'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function note(Evaluation $evaluation, User $eleve, string $note): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id, 'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->id, 'klassci_etudiant_id' => $eleve->klassci_id, 'attempt' => 1,
            'status' => 'soumis', 'feedback' => null, 'score' => $note, 'note_sur_20' => $note, 'submitted_at' => now(),
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $etudiants */
    private function roster(array $etudiants): void
    {
        $this->app->instance(ClasseRoster::class, new class($etudiants) implements ClasseRoster
        {
            /** @param  array<int, array<string, mixed>>  $etudiants */
            public function __construct(private readonly array $etudiants) {}

            public function etudiants(int $classeId, string $userToken): array
            {
                return $classeId === BilanDeClasseTest::KLASSCI && $userToken === 'jeton-coordinateur' ? $this->etudiants : [];
            }
        });
    }

    private function bilan(User $lecteur, string $periode = '', ?int $classe = null): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/lms/classes/'.($classe ?? $this->classe->id).'/resultats'.$periode);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');

        return $reponse;
    }

    /** @return list<array{mixed, mixed, mixed}> */
    private function classement(TestResponse $reponse): array
    {
        return array_values(array_map(
            static fn (array $l): array => [$l['nom'], $l['moyenne_generale'], $l['rang']],
            (array) $reponse->json('data.eleves'),
        ));
    }

    private function matiereDe(string $titre): int
    {
        return (int) Evaluation::query()->where('titre', $titre)->value('matiere_id');
    }
}
