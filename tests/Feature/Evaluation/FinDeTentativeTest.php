<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\LocalEvaluationWindow;
use App\Services\KlassciProxyService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * C28 et C29 : la fin d'une tentative, une seule règle, dans les deux types d'école.
 *
 * Décision de l'utilisateur : une copie remise après l'échéance « est refusée mais
 * gardée en brouillon et verrouillée pour toute modification ».
 *
 * - L'échéance est écrite sur la copie au démarrage (`ends_at`) : la plus proche
 *   de la fin de la fenêtre de la source et de `started_at + duree_minutes`. La
 *   remise ne demande donc rien à KLASSCI.
 * - Une marge de 60 s couvre la remise automatique du front, qui part jusqu'à 30 s
 *   après la fin de la fenêtre.
 * - Après la marge : 409 `evaluation_attempt_locked`, la copie passe `verrouille`,
 *   garde les réponses reçues, n'est pas notée, et compte comme une tentative.
 * - Une tentative échue jamais remise se LIT verrouillée partout.
 * - L'entraînement n'a pas d'échéance.
 *
 * Avant : la remise ne lisait aucune heure, et deux notions de fin coexistaient
 * (C29 : `isTerminee` ignorait `deadline_at`).
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class FinDeTentativeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_ecole_du_lms_la_remise_apres_la_marge_est_refusee_et_la_copie_verrouillee(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 60);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->assertEcheance(now()->addMinutes(50), $copie);

        $this->travelTo(now()->addMinutes(50)->addSeconds(61));
        $reponse = $this->remettre($eleve, $evaluation, 'a');

        $this->assertVerrouillee($reponse, $copie, 'a');
    }

    public function test_ecole_du_lms_la_marge_accepte_une_remise_59_secondes_apres_l_echeance(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 60);
        $copie = $this->demarrer($eleve, $evaluation);

        $this->travelTo(now()->addMinutes(50)->addSeconds(59));
        $this->remettre($eleve, $evaluation, 'a')->assertCreated();

        self::assertSame('soumis', $copie->fresh()?->getRawOriginal('status'));
    }

    public function test_une_seconde_remise_d_une_copie_verrouillee_rend_le_meme_409_sans_rien_ecrire(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 60);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addHour());
        $this->remettre($eleve, $evaluation, 'a')->assertStatus(409);

        $reponse = $this->remettre($eleve, $evaluation, 'b');

        $this->assertVerrouillee($reponse, $copie, 'a');
    }

    /** L'échéance est la plus proche de la fin de la fenêtre et de la durée de la tentative. */
    public function test_l_echeance_est_la_plus_proche_de_la_fenetre_et_de_la_duree(): void
    {
        [$eleve, $parLaDuree] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 30, ['deadline_at' => now()->addHours(5)]);
        $classe = Classe::query()->findOrFail($parLaDuree->classe_id);
        $parLaFenetre = $this->evaluation($classe, Matiere::query()->findOrFail($parLaDuree->matiere_id), now()->subMinutes(10), 60, [
            'deadline_at' => now()->addMinutes(10),
        ]);

        $this->assertEcheance(now()->addMinutes(30), $this->demarrer($eleve, $parLaDuree));
        $this->assertEcheance(now()->addMinutes(10), $this->demarrer($eleve, $parLaFenetre));
    }

    public function test_une_copie_verrouillee_compte_comme_une_tentative(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 30, [
            'deadline_at' => now()->addHours(5), 'allow_retake' => true, 'max_attempts' => 1,
        ]);
        $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addMinutes(32));
        $this->remettre($eleve, $evaluation, 'a')->assertStatus(409);

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertForbidden()
            ->assertJsonPath('reason', 'evaluation_max_attempts');
    }

    /** Q4 : aucune commande périodique ; le statut se dérive de `ends_at` à la lecture. */
    public function test_une_tentative_echue_jamais_remise_se_lit_verrouillee_et_compte(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 30, [
            'deadline_at' => now()->addHours(5), 'allow_retake' => true, 'max_attempts' => 1,
        ]);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addMinutes(32));

        // « Ma copie » n'est pas validée contre la spec : écart préexistant, hors C28
        // (`questions` en `allOf`, que le validateur refuse ; `answers` vide rendu `[]`).
        $this->asTenant($eleve)->getJson($this->de($evaluation).'/my-submission')->assertOk()
            ->assertJsonPath('data.status', 'verrouille')
            ->assertJsonPath('data.ends_at', now()->subMinutes(2)->toIso8601ZuluString('microsecond'));
        $this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk()
            ->assertJsonPath('data.0.student_submission.status', 'verrouille');
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertForbidden()
            ->assertJsonPath('reason', 'evaluation_max_attempts');
        self::assertSame('en_cours', $copie->fresh()?->getRawOriginal('status'), 'la lecture n\'écrit rien');
    }

    public function test_une_tentative_echue_ne_se_reprend_pas_une_reprise_permise_en_ouvre_une_autre(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 30, [
            'deadline_at' => now()->addHours(5), 'allow_retake' => true, 'max_attempts' => 2,
        ]);
        $premiere = $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addMinutes(32));

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertOk()
            ->assertJsonPath('message', 'Évaluation démarrée')
            ->assertJsonPath('data.attempt', 2);
        self::assertNotSame($premiere->getKey(), $this->copieOuverte($eleve, $evaluation)->getKey());
    }

    public function test_l_entrainement_n_a_pas_d_echeance(): void
    {
        [$eleve, $passee] = $this->eleveEtEvaluationLocale(now()->subHours(3), 60);
        $copie = $this->demarrer($eleve, $passee);
        self::assertNull($copie->ends_at);

        $this->travelTo(now()->addDay());
        $this->remettre($eleve, $passee, 'a')->assertCreated();
    }

    /** Course : une remise tardive n'écrase pas une copie remise entre-temps (écriture conditionnelle). */
    public function test_une_remise_tardive_n_ecrase_pas_une_copie_remise_entre_temps(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subMinutes(10), 60);
        $copie = $this->demarrer($eleve, $evaluation);
        $this->travelTo(now()->addHour());
        $fait = false;
        DB::beforeExecuting(function (string $requete) use (&$fait, $copie): void {
            if ($fait || preg_match('/^update [`"]evaluation_submissions[`"]/i', $requete) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->where('id', $copie->getKey())
                ->update(['status' => 'soumis', 'answers' => json_encode(['gagnante' => 'x'])]);
        });

        $this->remettre($eleve, $evaluation, 'a')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'evaluation_attempt_locked');

        self::assertTrue($fait, 'la remise tardive a tenté une écriture');
        $apres = DB::table('evaluation_submissions')->where('id', $copie->getKey())->first();
        self::assertSame('soumis', $apres?->status);
        self::assertSame(['gagnante' => 'x'], json_decode((string) $apres?->answers, true));
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_ecole_klassci_la_remise_apres_la_marge_est_refusee_sans_appel_klassci(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluation(finDeFenetre: now()->addMinutes(30));
        $copie = $this->demarrer($eleve, $evaluation);
        $this->assertEcheance(now()->addMinutes(30), $copie);
        $this->klassciEnPanne();

        $this->travelTo(now()->addMinutes(30)->addSeconds(61));
        $reponse = $this->remettre($eleve, $evaluation, 'a');

        $this->assertVerrouillee($reponse, $copie, 'a');
    }

    public function test_ecole_klassci_dans_le_delai_la_remise_passe_meme_klassci_en_panne(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluation(finDeFenetre: now()->addMinutes(30));
        $copie = $this->demarrer($eleve, $evaluation);
        $this->klassciEnPanne();

        $this->travelTo(now()->addMinutes(30)->addSeconds(59));
        $this->remettre($eleve, $evaluation, 'a')->assertCreated();

        self::assertSame('soumis', $copie->fresh()?->getRawOriginal('status'));
    }

    public function test_ecole_klassci_sans_fin_de_fenetre_l_echeance_est_la_duree(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluation(finDeFenetre: null);

        $this->assertEcheance(now()->addMinutes(60), $this->demarrer($eleve, $evaluation));
    }

    // ───────────────────────── C29 : une seule fin d'évaluation

    public function test_la_fin_de_l_evaluation_suit_deadline_at_partout(): void
    {
        $prolongee = Evaluation::factory()->make([
            'date_evaluation' => now()->subHours(2), 'duree_minutes' => 60, 'deadline_at' => now()->addHour(), 'status' => 'planifiee',
        ]);
        $avancee = Evaluation::factory()->make([
            'date_evaluation' => now()->subMinutes(40), 'duree_minutes' => 60, 'deadline_at' => now()->subMinutes(30), 'status' => 'planifiee',
        ]);

        self::assertFalse($prolongee->isTerminee());
        self::assertFalse(LocalEvaluationWindow::of($prolongee)['has_ended']);
        self::assertTrue($avancee->isTerminee());
        self::assertTrue(LocalEvaluationWindow::of($avancee)['has_ended']);
    }

    public function test_une_evaluation_prolongee_par_deadline_at_ne_se_rejoue_pas_en_entrainement(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluationLocale(now()->subHours(2), 60, ['deadline_at' => now()->addHour()]);

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertOk()
            ->assertJsonPath('is_practice', false);
    }

    // ───────────────────────── harnais

    private function assertVerrouillee(\Illuminate\Testing\TestResponse $reponse, EvaluationSubmission $copie, string $reponseGardee): void
    {
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/submit', 'post');
        $reponse->assertStatus(409)->assertJsonPath('reason', 'evaluation_attempt_locked');

        $apres = DB::table('evaluation_submissions')->where('id', $copie->getKey())->first();
        self::assertSame('verrouille', $apres?->status);
        self::assertNull($apres?->score);
        self::assertNull($apres?->note_sur_20);
        self::assertNull($apres?->submitted_at);
        $question = EvaluationQuestion::query()->where('evaluation_id', $copie->evaluation_id)->firstOrFail();
        self::assertSame([(string) $question->getKey() => $reponseGardee], json_decode((string) $apres?->answers, true));
    }

    private function assertEcheance(CarbonInterface $attendue, EvaluationSubmission $copie): void
    {
        self::assertInstanceOf(CarbonInterface::class, $copie->ends_at);
        self::assertSame($attendue->toIso8601String(), $copie->ends_at->toIso8601String());
    }

    private function demarrer(User $eleve, Evaluation $evaluation): EvaluationSubmission
    {
        $id = $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk()->json('data.id');

        return EvaluationSubmission::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function remettre(User $eleve, Evaluation $evaluation, string $choix): \Illuminate\Testing\TestResponse
    {
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();

        return $this->asTenant($eleve)->postJson($this->de($evaluation).'/submit', [
            'answers' => [(string) $question->getKey() => $choix],
        ]);
    }

    private function copieOuverte(User $eleve, Evaluation $evaluation): EvaluationSubmission
    {
        return EvaluationSubmission::query()->withoutGlobalScopes()
            ->where('evaluation_id', $evaluation->getKey())->where('student_id', $eleve->getKey())
            ->where('status', 'en_cours')->orderByDesc('attempt')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array{User, Evaluation}
     */
    private function eleveEtEvaluationLocale(CarbonInterface $date, int $duree, array $surcharges = []): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');

        return [$eleve, $this->evaluation($classe, $this->matiere($ecole, 'Maths'), $date, $duree, $surcharges)];
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function evaluation(Classe $classe, Matiere $matiere, CarbonInterface $date, int $duree, array $surcharges = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'is_published' => true,
            'is_online' => true,
            'status' => 'planifiee',
            'date_evaluation' => $date,
            'duree_minutes' => $duree,
            'deadline_at' => null,
            'allow_retake' => false,
            'max_attempts' => 1,
            'show_results' => true,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    /**
     * Une école KLASSCI dont la programmation ouvre l'évaluation, avec la fin de
     * fenêtre donnée (forme de `programmation.window`).
     *
     * @return array{User, Evaluation}
     */
    private function eleveKlassciEtEvaluation(?CarbonInterface $finDeFenetre): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(), 'klassci_classe_id' => 77, 'klassci_evaluation_id' => 900,
            'is_published' => true, 'status' => 'planifiee', 'is_online' => true,
            'date_evaluation' => now()->subMinutes(10), 'duree_minutes' => 60, 'deadline_at' => null,
            'allow_retake' => false, 'max_attempts' => 1, 'show_results' => true,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);
        $fenetre = ['is_open' => true, 'has_started' => true, 'has_ended' => false, 'start_at' => now()->subMinutes(10)->toIso8601String()]
            + ($finDeFenetre === null ? [] : ['end_at' => $finDeFenetre->toIso8601String()]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($fenetre): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 77, 'nom' => 'Terminale D']]]);
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'evaluations', 'GET')
                ->andReturn(['data' => [['id' => 900, 'programmation' => ['window' => $fenetre]]]]);
        });
        $this->disableKlassciMiddleware();

        return [$eleve, $evaluation];
    }

    /** Après le démarrage, KLASSCI ne répond plus : la remise ne doit pas en dépendre. */
    private function klassciEnPanne(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new RuntimeException('KLASSCI indisponible'));
        });
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
