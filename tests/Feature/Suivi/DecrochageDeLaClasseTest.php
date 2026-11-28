<?php

declare(strict_types=1);

namespace Tests\Feature\Suivi;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1704 : `GET /api/lms/classes/{classe}/decrochage`, sur un jeu dont le résultat est connu d'avance.
 * Seuils par défaut : 7 jours sans connexion, 2 remises manquées, 50 % de chapitres non ouverts.
 *
 * | élève | dernière connexion | remises (sur 3) | chapitres ouverts (sur 4) | à suivre                |
 * |-------|--------------------|-----------------|---------------------------|-------------------------|
 * | Awa   | il y a 1 jour      | 3               | 4                         | non                     |
 * | Binta | jamais             | 3               | 4                         | sans connexion (jamais) |
 * | Coffi | il y a 12 jours    | 3               | 4                         | sans connexion (12 j)   |
 * | Dédé  | aujourd'hui        | 1               | 1                         | remises, chapitres      |
 *
 * Dédé : une copie verrouillée (remise hors délai) et une copie d'entraînement ne sont pas des
 * remises. Le bruit ne compte jamais : un brouillon, une évaluation non publiée, sur papier, pas
 * encore terminée ou terminée il y a plus de 30 jours ; une leçon publiée il y a moins de 7 jours,
 * un brouillon de leçon, un chapitre supprimé. Vraie route, vrai jeton (`asTenant`).
 */
final class DecrochageDeLaClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/classes/{classe}/decrochage';

    private const KLASSCI = 900091;

    private Institution $ecole;

    private Classe $classe;

    private Matiere $maths;

    private User $formateur;

    /** @var array<string, User> */
    private array $eleves = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_les_trois_regles_nomment_leur_raison_et_leur_valeur(): void
    {
        $this->jeu();

        $reponse = $this->decrochage($this->membre($this->ecole, 'coordinateur'))->assertOk();

        $reponse->assertJsonPath('data.classe', ['id' => $this->classe->id, 'libelle' => '6e A'])
            ->assertJsonPath('data.effectif', 4)
            ->assertJsonPath('data.reglages', ['jours_sans_connexion' => 7, 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 50]);
        self::assertSame([
            ['Dédé', [
                ['regle' => 'remises_manquees', 'manquees' => 2, 'attendues' => 3, 'seuil' => 2],
                ['regle' => 'chapitres_non_ouverts', 'non_ouverts' => 3, 'total' => 4, 'part' => 75, 'seuil' => 50],
            ]],
            ['Binta', [['regle' => 'sans_connexion', 'jours' => null, 'seuil' => 7]]],
            ['Coffi', [['regle' => 'sans_connexion', 'jours' => 12, 'seuil' => 7]]],
        ], array_map(static fn (array $l): array => [$l['nom'], $l['raisons']], (array) $reponse->json('data.a_suivre')));
        $reponse->assertJsonPath('data.a_suivre.1.derniere_connexion', null)
            ->assertJsonPath('data.a_suivre.2.compte_id', $this->eleves['Coffi']->id)
            ->assertJsonPath('data.a_suivre.2.derniere_connexion', '2026-11-16T10:00:00+00:00');
    }

    public function test_les_seuils_de_l_ecole_font_foi_et_un_reglage_invalide_retombe_sur_le_defaut(): void
    {
        $this->jeu(['seuil_reussite' => 12, 'decrochage' => ['jours_sans_connexion' => 15, 'remises_manquees' => 3, 'part_chapitres_non_ouverts' => 80]]);
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $reponse = $this->decrochage($coordinateur)->assertOk();
        self::assertSame(['Binta'], array_column((array) $reponse->json('data.a_suivre'), 'nom'));

        $this->ecole->forceFill(['settings' => ['decrochage' => ['jours_sans_connexion' => 0, 'remises_manquees' => 'deux', 'part_chapitres_non_ouverts' => 101]]])->save();
        $this->decrochage($coordinateur)->assertOk()
            ->assertJsonPath('data.reglages', ['jours_sans_connexion' => 7, 'remises_manquees' => 2, 'part_chapitres_non_ouverts' => 50]);
    }

    public function test_l_enseignant_lit_la_classe_qu_il_enseigne_et_seulement_elle(): void
    {
        $this->jeu();

        $this->decrochage($this->formateur)->assertOk()->assertJsonPath('data.effectif', 4);
        $this->decrochage($this->membre($this->ecole, 'enseignant'))->assertForbidden()->assertJsonPath('reason', 'class_not_taught');
    }

    public function test_ni_un_eleve_ni_une_autre_ecole_ni_un_anonyme_ne_lisent_la_liste(): void
    {
        $this->jeu();

        $this->decrochage($this->eleves['Awa'])->assertForbidden();
        $this->decrochage($this->membre($this->ecole(), 'coordinateur'))->assertNotFound()->assertJsonPath('reason', 'class_out_of_scope');
        $this->anonyme()->getJson('/api/lms/classes/'.$this->classe->id.'/decrochage')->assertUnauthorized();
    }

    public function test_une_classe_sans_evaluation_ni_chapitre_ne_signale_que_les_connexions(): void
    {
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e B');
        $this->eleve('Eli', now()->subDays(2));
        $this->eleve('Fifi', null);

        $this->decrochage($this->membre($this->ecole, 'coordinateur'))->assertOk()
            ->assertJsonPath('data.effectif', 2)
            ->assertJsonPath('data.a_suivre.0.nom', 'Fifi')
            ->assertJsonPath('data.a_suivre.0.raisons', [['regle' => 'sans_connexion', 'jours' => null, 'seuil' => 7]])
            ->assertJsonCount(1, 'data.a_suivre');
    }

    /**
     * École KLASSCI : la classe par son identité KLASSCI, ses évaluations par `klassci_classe_id`, et une
     * copie d'avant `student_id`, rattachée par l'identité KLASSCI de l'élève, est bien une remise.
     */
    public function test_ecole_klassci_les_evaluations_et_les_copies_d_avant_se_rattachent(): void
    {
        $this->ecole = $this->ecole(InstitutionMode::Klassci);
        $this->ecole->forceFill(['settings' => ['decrochage' => ['jours_sans_connexion' => 7, 'remises_manquees' => 1, 'part_chapitres_non_ouverts' => 50]]])->save();
        $this->classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::KLASSCI, 'libelle' => 'BTS 1']);
        $eleve = $this->eleve('Ama', now());
        $eleve->forceFill(['klassci_id' => 9001])->save();
        $rendue = $this->evaluation('Rendue', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI]);
        $this->evaluation('Oubliée', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI]);
        $this->evaluation('Autre classe', ['classe_id' => null, 'klassci_classe_id' => self::KLASSCI + 1]);
        $this->copie($rendue, $eleve, 'soumis', ['student_id' => null, 'klassci_etudiant_id' => 9001]);
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-coordinateur'])->save();
        $this->disableKlassciMiddleware();

        $reponse = $this->decrochage($coordinateur, self::KLASSCI)->assertOk();

        $reponse->assertJsonPath('data.classe.id', $this->classe->id)
            ->assertJsonPath('data.a_suivre.0.nom', 'Ama')
            ->assertJsonPath('data.a_suivre.0.raisons', [['regle' => 'remises_manquees', 'manquees' => 1, 'attendues' => 2, 'seuil' => 1]]);
    }

    /** @param  array<string, mixed>  $reglages */
    private function jeu(array $reglages = []): void
    {
        $this->ecole = $this->ecole();
        $this->ecole->forceFill(['settings' => $reglages])->save();
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->maths = $this->matiere($this->ecole, 'Maths');
        $this->formateur = $this->membre($this->ecole, 'enseignant');
        $this->programme($this->classe, $this->maths, $this->formateur);

        $this->eleves = [
            'Awa' => $this->eleve('Awa', now()->subDay()),
            'Binta' => $this->eleve('Binta', null),
            'Coffi' => $this->eleve('Coffi', now()->subDays(12)),
            'Dédé' => $this->eleve('Dédé', now()),
        ];
        $retire = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($retire, $this->classe, 'abandonne');

        $attendues = [
            $this->evaluation('E1', ['date_evaluation' => now()->subDays(10)]),
            $this->evaluation('E2', ['date_evaluation' => now()->subDays(8), 'deadline_at' => now()->subDays(7)]),
            $this->evaluation('E3', ['date_evaluation' => now()->subDays(6)]),
        ];
        $this->bruit();
        foreach (['Awa', 'Binta', 'Coffi'] as $nom) {
            foreach ($attendues as $i => $evaluation) {
                $this->copie($evaluation, $this->eleves[$nom], $i === 0 ? 'corrige' : 'soumis');
            }
        }
        $this->copie($attendues[0], $this->eleves['Dédé'], 'soumis');
        $this->copie($attendues[1], $this->eleves['Dédé'], 'verrouille');
        $this->copie($attendues[2], $this->eleves['Dédé'], 'soumis', ['feedback' => '[PRACTICE] entraînement']);

        $chapitres = $this->chapitres($this->lecon(now()->subDays(10)), 4);
        $this->chapitres($this->lecon(now()->subDays(2)), 2);
        $this->chapitres($this->lecon(null), 1);
        $supprime = $this->chapitres($this->lecon(now()->subDays(10)), 1)[0];
        $supprime->delete();
        foreach (['Awa', 'Binta', 'Coffi'] as $nom) {
            $this->ouvrir($this->eleves[$nom], [...$chapitres, $supprime]);
        }
        $this->ouvrir($this->eleves['Dédé'], [$chapitres[0], $supprime]);
        $this->ouvrir($retire, $chapitres);
    }

    /** Les évaluations qui ne sont pas attendues : aucune ne compte. */
    private function bruit(): void
    {
        $this->evaluation('Brouillon', ['status' => 'brouillon', 'date_evaluation' => now()->subDays(5)]);
        $this->evaluation('Non publiée', ['is_published' => false, 'date_evaluation' => now()->subDays(5)]);
        $this->evaluation('Sur papier', ['is_online' => false, 'date_evaluation' => now()->subDays(5)]);
        $this->evaluation('À venir', ['status' => 'planifiee', 'date_evaluation' => now()->addDays(2)]);
        $this->evaluation('En cours', ['status' => 'en_cours', 'date_evaluation' => now()->subMinutes(10)]);
        $this->evaluation('Ancienne', ['date_evaluation' => now()->subDays(40)]);
        $autre = $this->classe($this->ecole, '6e Z');
        $this->evaluation('Autre classe', ['classe_id' => $autre->id, 'date_evaluation' => now()->subDays(5)]);
    }

    private function eleve(string $nom, ?Carbon $connexion): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $eleve->forceFill(['name' => $nom])->save();
        DB::table('users')->where('id', $eleve->id)->update(['last_login_at' => $connexion]);
        $this->inscrire($eleve, $this->classe, 'actif');

        return $eleve;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(string $titre, array $surcharges = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'matiere_id' => null,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null,
            'titre' => $titre, 'is_published' => true, 'is_online' => true, 'status' => 'terminee',
            'date_evaluation' => now()->subDays(10), 'deadline_at' => null, 'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function copie(Evaluation $evaluation, User $eleve, string $statut, array $surcharges = []): void
    {
        EvaluationSubmission::factory()->create(array_merge([
            'evaluation_id' => $evaluation->id, 'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->id, 'klassci_etudiant_id' => $eleve->klassci_id, 'attempt' => 1,
            'status' => $statut, 'feedback' => null, 'submitted_at' => now()->subDays(5),
        ], $surcharges));
    }

    private function lecon(?Carbon $publication): Lesson
    {
        return Lesson::factory()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id,
            'matiere_id' => $this->maths->id, 'enseignant_id' => $this->formateur->id,
            'status' => $publication === null ? 'draft' : 'published', 'published_at' => $publication,
        ]);
    }

    /** @return list<Chapter> */
    private function chapitres(Lesson $lecon, int $nombre): array
    {
        $chapitres = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $chapitres[] = Chapter::factory()->create([
                'lesson_id' => $lecon->id, 'institution_id' => $this->ecole->id,
                'matiere_id' => $this->maths->id, 'enseignant_id' => $this->formateur->id, 'order' => $i,
            ]);
        }

        return $chapitres;
    }

    /** @param  list<Chapter>  $chapitres */
    private function ouvrir(User $eleve, array $chapitres): void
    {
        foreach ($chapitres as $chapitre) {
            DB::table('chapter_progress')->insert([
                'user_id' => $eleve->id, 'chapter_id' => $chapitre->id, 'institution_id' => $this->ecole->id,
                'time_spent_seconds' => 60, 'quiz_passed' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function decrochage(User $lecteur, ?int $classe = null): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/lms/classes/'.($classe ?? $this->classe->id).'/decrochage');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');

        return $reponse;
    }
}
