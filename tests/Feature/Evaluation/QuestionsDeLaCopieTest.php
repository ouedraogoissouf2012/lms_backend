<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\Student\CopiesFinales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1702 (D2) : les questions d'une copie ont UN lieu, sa `presentation`, écrite au démarrage.
 *
 * - Le mélange (`shuffle_questions`) est FIGÉ par tentative : l'écran de passage, la copie de
 *   l'élève et la correction lisent le même ordre. Avant, `->shuffle()` le recalculait à chaque
 *   lecture de la copie, et l'écran de passage ne mélangeait rien.
 * - La note, la finalité d'une copie et sa correction portent sur les questions POSÉES : la base
 *   du tirage (D3), à comportement constant tant qu'on pose tout.
 * - Une copie d'avant la présentation garde toutes les questions, dans l'ordre de l'évaluation.
 *
 * Six questions QCM d'un point ; le hasard est fixé (graine 7). Vraie route, vrai jeton.
 */
final class QuestionsDeLaCopieTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const GRAINE = 7;

    private Institution $ecole;

    private Classe $classe;

    private Evaluation $evaluation;

    /** @var list<int> les questions, dans l'ordre de l'évaluation */
    private array $ordre = [];

    public function test_le_melange_est_fige_par_tentative_et_lu_partout_pareil(): void
    {
        $awa = $this->jeu(true);
        $attendu = (new Randomizer(new Xoshiro256StarStar(self::GRAINE)))->shuffleArray($this->ordre);
        self::assertNotSame($this->ordre, $attendu, 'La graine doit mélanger, sinon le test ne prouve rien.');
        $this->app->instance(Randomizer::class, new Randomizer(new Xoshiro256StarStar(self::GRAINE)));

        $this->asTenant($awa)->postJson($this->de().'/start')->assertSuccessful();

        self::assertSame($attendu, EvaluationSubmission::query()->sole()->presentation['questions'] ?? null);
        self::assertSame($attendu, $this->idsALEcran($awa));
        self::assertSame($attendu, $this->idsALEcran($awa), 'Une seconde lecture rend le même ordre.');
        self::assertSame($attendu, $this->idsDeLaCopie($awa));
        self::assertSame($attendu, $this->idsDeLaCopie($awa));
    }

    public function test_sans_melange_l_ordre_est_celui_de_l_evaluation(): void
    {
        $awa = $this->jeu(false);

        $this->asTenant($awa)->postJson($this->de().'/start')->assertSuccessful();

        self::assertSame($this->ordre, EvaluationSubmission::query()->sole()->presentation['questions'] ?? null);
        self::assertSame($this->ordre, $this->idsALEcran($awa));
    }

    public function test_la_note_et_la_finalite_portent_sur_les_seules_questions_posees(): void
    {
        $awa = $this->jeu(false);
        $dissertation = EvaluationQuestion::factory()->create(['evaluation_id' => $this->evaluation->id, 'type' => 'dissertation', 'points' => 4, 'ordre' => 99]);
        $posees = array_slice($this->ordre, 0, 2);
        $copie = EvaluationSubmission::factory()->create([
            'evaluation_id' => $this->evaluation->id, 'institution_id' => $this->ecole->id, 'student_id' => $awa->id,
            'attempt' => 1, 'status' => 'soumis', 'feedback' => null, 'submitted_at' => now(),
            'presentation' => ['questions' => $posees],
            'answers' => [(string) $posees[0] => 'a', (string) $posees[1] => 'b', (string) $this->ordre[2] => 'a'],
        ]);

        app(EvaluationGradingService::class)->calculateScore($copie);

        // 1 point sur 2 posés : 10 sur 20. La troisième question, non posée, ne compte pas ; la
        // dissertation, non posée, ne rend pas la note provisoire.
        self::assertSame('10.00', number_format((float) $copie->note_sur_20, 2, '.', ''));
        self::assertTrue(app(CopiesFinales::class)->estFinale($copie->fresh() ?? $copie));
        self::assertNotContains($dissertation->id, $this->idsDeLaCopie($awa));
    }

    public function test_une_copie_d_avant_la_presentation_garde_toutes_les_questions(): void
    {
        $awa = $this->jeu(true);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $this->evaluation->id, 'institution_id' => $this->ecole->id, 'student_id' => $awa->id,
            'attempt' => 1, 'status' => 'soumis', 'feedback' => null, 'submitted_at' => now(), 'presentation' => null,
        ]);

        self::assertSame($this->ordre, $this->idsDeLaCopie($awa));
        self::assertSame($this->ordre, $this->idsDeLaCopie($awa), 'Plus de mélange recalculé à chaque lecture.');
    }

    private function jeu(bool $melange): User
    {
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e A');
        $maths = $this->matiere($this->ecole, 'Maths');
        $this->programme($this->classe, $maths, null);
        $enseignant = $this->membre($this->ecole, 'enseignant');
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'classe_nom' => $this->classe->libelle,
            'matiere_id' => $maths->id, 'matiere_nom' => $maths->libelle, 'enseignant_id' => $enseignant->id,
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => true, 'is_online' => true, 'allow_retake' => false, 'max_attempts' => 1, 'show_results' => true,
            'notes_published' => false, 'status' => 'planifiee', 'date_evaluation' => now()->subMinutes(10), 'duree_minutes' => 60,
            'deadline_at' => null, 'bareme' => 20, 'coefficient' => '1.00', 'shuffle_questions' => $melange,
        ]);
        $this->ordre = [];
        foreach (range(1, 6) as $rang) {
            $this->ordre[] = (int) EvaluationQuestion::factory()->create([
                'evaluation_id' => $this->evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a'], 'points' => 1, 'ordre' => $rang,
            ])->id;
        }
        $this->disableKlassciMiddleware();
        $awa = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($awa, $this->classe, 'actif');

        return $awa;
    }

    /** @return list<int> les questions de l'écran de passage (`GET /evaluations/{id}`), dans l'ordre */
    private function idsALEcran(User $eleve): array
    {
        $reponse = $this->asTenant($eleve)->getJson($this->de())->assertOk();

        return array_map(static fn (array $q): int => (int) $q['id'], (array) $reponse->json('data.questions'));
    }

    /** @return list<int> les questions de la copie de l'élève (`GET /evaluations/{id}/my-submission`) */
    private function idsDeLaCopie(User $eleve): array
    {
        $reponse = $this->asTenant($eleve)->getJson($this->de().'/my-submission');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');

        return array_map(static fn (array $q): int => (int) $q['id'], (array) $reponse->assertOk()->json('data.questions'));
    }

    private function de(): string
    {
        return '/api/evaluations/'.$this->evaluation->id;
    }
}
