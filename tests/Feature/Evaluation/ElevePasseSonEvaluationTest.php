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
use App\Services\KlassciProxyService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * D72, E2b : dans une école créée depuis le LMS, l'élève démarre, reprend et
 * remet son évaluation.
 *
 * Avant : `/start` et `time-status` répondaient 401 (`no_token`) ; la fenêtre
 * se lisait chez KLASSCI. Et, dans les DEUX types d'école, deux `/start`
 * simultanés finissaient en 500 sur l'unique des copies.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class ElevePasseSonEvaluationTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    // ───────────────────────── démarrer, école créée depuis le LMS

    public function test_l_eleve_sans_jeton_demarre_une_evaluation_ouverte(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        Http::preventStrayRequests();

        $reponse = $this->asTenant($eleve)->postJson($this->de($evaluation).'/start');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/start', 'post');
        $reponse
            ->assertOk()
            ->assertJsonPath('message', 'Évaluation démarrée')
            ->assertJsonPath('data.student_id', $eleve->getKey())
            ->assertJsonPath('data.klassci_etudiant_id', null)
            ->assertJsonPath('window.is_open', true)
            ->assertJsonPath('is_practice', false);
    }

    public function test_la_fenetre_locale_ferme_avant_l_ouverture_et_sans_date(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $avenir = $this->evaluation($classe, $maths, now()->addDay(), 60);
        $sansDate = $this->evaluation($classe, $maths, null, 60);

        foreach ([$avenir, $sansDate] as $evaluation) {
            $reponse = $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
                ->assertForbidden()
                ->assertJsonPath('reason', 'evaluation_window_closed');
            $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/start', 'post');
        }

        self::assertSame(0, $this->copies());
    }

    public function test_une_evaluation_passee_se_rejoue_en_entrainement(): void
    {
        // Même règle que l'école créée depuis KLASSCI : une évaluation terminée
        // (date + durée dépassées) se rejoue en entraînement.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $passee = $this->evaluation($classe, $maths, now()->subHours(3), 60);

        $this->asTenant($eleve)->postJson($this->de($passee).'/start')
            ->assertOk()
            ->assertJsonPath('is_practice', true);
    }

    public function test_une_evaluation_hors_de_ses_classes_actives_rend_404(): void
    {
        [$ecole, $eleve, , $maths] = $this->eleveInscrit();
        $autre = $this->evaluation($this->classe($ecole, '5e B'), $maths, now()->subMinutes(10), 60);
        $inactive = $this->classe($ecole, 'Atelier');
        $this->inscrire($eleve, $inactive, 'inactif');
        $deLInactive = $this->evaluation($inactive, $maths, now()->subMinutes(10), 60);

        $this->asTenant($eleve)->postJson($this->de($autre).'/start')->assertNotFound();
        $this->asTenant($eleve)->postJson($this->de($deLInactive).'/start')->assertNotFound();
        self::assertSame(0, $this->copies());
    }

    public function test_les_reprises_et_le_quota_suivent_l_evaluation(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $sansReprise = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['allow_retake' => false]);
        $this->copie($sansReprise, $eleve, 'soumis');
        $quota = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['allow_retake' => true, 'max_attempts' => 1]);
        $this->copie($quota, $eleve, 'soumis');

        $this->asTenant($eleve)->postJson($this->de($sansReprise).'/start')
            ->assertForbidden()->assertJsonPath('reason', 'evaluation_retake_forbidden');
        $this->asTenant($eleve)->postJson($this->de($quota).'/start')
            ->assertForbidden()->assertJsonPath('reason', 'evaluation_max_attempts');
    }

    // ───────────────────────── reprendre et remettre

    public function test_un_second_demarrage_reprend_la_meme_copie(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        $premiere = $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk()->json('data.id');

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertOk()
            ->assertJsonPath('message', 'Reprise de la tentative en cours')
            ->assertJsonPath('data.id', $premiere);
    }

    public function test_l_eleve_remet_sa_copie_et_un_camarade_ne_remet_pas_la_sienne(): void
    {
        [$ecole, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk();
        $camarade = $this->membre($ecole, 'etudiant');
        $this->inscrire($camarade, $classe, 'actif');

        $this->asTenant($camarade)->postJson($this->de($evaluation).'/submit', $this->reponses($evaluation))
            ->assertStatus(409);
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/submit', $this->reponses($evaluation))
            ->assertCreated();

        $copie = EvaluationSubmission::withoutGlobalScopes()->sole();
        self::assertSame($eleve->getKey(), $copie->student_id);
        self::assertNull($copie->klassci_etudiant_id);
        self::assertSame('soumis', $copie->status);
    }

    public function test_time_status_rend_la_fenetre_locale(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);

        $this->asTenant($eleve)->getJson($this->de($evaluation).'/time-status')
            ->assertOk()
            ->assertJsonPath('data.window.is_open', true)
            ->assertJsonStructure(['data' => ['server_time']]);
    }

    public function test_dans_une_ecole_klassci_le_demarrage_sans_jeton_reste_un_401(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $evaluation = Evaluation::factory()->create(['institution_id' => $ecole->getKey(), 'is_published' => true, 'status' => 'planifiee']);

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
    }

    // ───────────────────────── la course entre deux /start

    public function test_le_demarrage_perdant_d_une_course_reprend_la_copie_concurrente_ecole_du_lms(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        $concurrente = $this->courseAvant($evaluation, $eleve, klassci: null, statut: 'en_cours');

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertOk()
            ->assertJsonPath('message', 'Reprise de la tentative en cours')
            ->assertJsonPath('data.id', $concurrente());
    }

    public function test_le_demarrage_perdant_d_une_course_reprend_la_copie_concurrente_ecole_klassci(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluationOuverte();
        $concurrente = $this->courseAvant($evaluation, $eleve, klassci: 501, statut: 'en_cours');

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertOk()
            ->assertJsonPath('message', 'Reprise de la tentative en cours')
            ->assertJsonPath('data.id', $concurrente());
    }

    public function test_si_la_copie_concurrente_est_deja_remise_le_perdant_recoit_409(): void
    {
        // Jamais la reprise d'une copie remise, jamais un 500.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['allow_retake' => true, 'max_attempts' => 3]);
        $this->courseAvant($evaluation, $eleve, klassci: null, statut: 'soumis');

        $reponse = $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'evaluation_attempt_conflict');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/start', 'post');
    }

    public function test_dans_une_ecole_klassci_le_perdant_d_une_course_deja_remise_recoit_409(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluationOuverte(['allow_retake' => true, 'max_attempts' => 3]);
        $this->courseAvant($evaluation, $eleve, klassci: 501, statut: 'soumis');

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'evaluation_attempt_conflict');
    }

    public function test_une_copie_heritee_invisible_fait_remonter_l_erreur_au_lieu_d_un_409(): void
    {
        // Scénario de la revue d'E2b : une copie héritée `attempt = 1` sans
        // établissement (invisible pour `ownedBy`), et une copie visible
        // `attempt = 2` déjà remise. Le compte vaut 1, la tentative créée porte
        // le numéro 2 et entre en conflit avec la copie visible : ce n'est PAS
        // une course gagnée par une autre requête, mais une anomalie de
        // données. Elle doit remonter (500), jamais un 409 silencieux.
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluationOuverte(['allow_retake' => true, 'max_attempts' => 5]);
        DB::table('evaluation_submissions')->insert([
            'evaluation_id' => $evaluation->getKey(), 'student_id' => null, 'klassci_etudiant_id' => 501,
            'attempt' => 1, 'status' => 'soumis', 'institution_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->copie($evaluation, $eleve, 'soumis', ['attempt' => 2, 'klassci_etudiant_id' => 501]);

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertStatus(500);
    }

    public function test_une_autre_erreur_de_base_au_demarrage_n_est_pas_prise_pour_une_course(): void
    {
        // Seul le conflit sur l'unique des copies est attrapé : toute autre
        // QueryException remonte, en 500.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        DB::beforeExecuting(function (string $query): void {
            if (preg_match('/insert into [`"]evaluation_submissions[`"]/i', $query) === 1) {
                throw new QueryException('sqlite', $query, [], new PDOException('NOT NULL constraint failed: evaluation_submissions.status'));
            }
        });

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertStatus(500);
    }

    public function test_la_violation_d_un_autre_unique_n_est_pas_prise_pour_une_course(): void
    {
        // D188 à la lettre : seul le conflit sur l'un des deux uniques des
        // copies est une course. Ici une copie ouverte apparaît pendant
        // l'insertion (une course la reprendrait en 200), mais la violation
        // porte sur un autre unique : elle remonte, en 500.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60);
        $fait = false;
        DB::beforeExecuting(function (string $query) use (&$fait, $evaluation, $eleve): void {
            if ($fait || preg_match('/insert into [`"]evaluation_submissions[`"]/i', $query) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->insert([
                'evaluation_id' => $evaluation->getKey(), 'student_id' => $eleve->getKey(), 'klassci_etudiant_id' => null,
                'attempt' => 1, 'status' => 'en_cours', 'started_at' => now(),
                'institution_id' => $evaluation->institution_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            throw new UniqueConstraintViolationException('sqlite', $query, [], new PDOException('UNIQUE constraint failed: evaluation_submissions.jeton_de_reprise'));
        });

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertStatus(500);
    }

    // ───────────────────────── C25 : résultats visibles

    public function test_show_results_faux_ne_fait_sortir_aucune_note_dans_la_liste_ecole_du_lms(): void
    {
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $cachee = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['show_results' => false]);
        $visible = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['show_results' => true]);
        $this->copie($cachee, $eleve, 'soumis', ['score' => 80, 'note_sur_20' => 16]);
        $this->copie($visible, $eleve, 'soumis', ['score' => 50, 'note_sur_20' => 10]);

        $copies = array_column((array) $this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk()->json('data'), 'student_submission', 'id');

        $this->assertAucuneNote($copies[$cachee->getKey()]);
        self::assertEquals(10, $copies[$visible->getKey()]['note_sur_20']);
    }

    public function test_show_results_faux_ne_fait_sortir_aucune_note_dans_la_liste_ecole_klassci(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluationOuverte(['show_results' => false]);
        $this->copie($evaluation, $eleve, 'soumis', ['score' => 80, 'note_sur_20' => 16, 'klassci_etudiant_id' => 501]);

        $copie = $this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk()->json('data.0.student_submission');

        $this->assertAucuneNote($copie);
    }

    public function test_show_results_faux_la_remise_ne_rend_aucune_note_ecole_du_lms(): void
    {
        // Revue croisée : la remise avec `show_results = false` n'avait aucun
        // test. Les clés `score` et `note_sur_20` sont RETIRÉES de la réponse et
        // de la copie (forme de la liste), là où « ma copie » les rend `null`.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['show_results' => false]);
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk();

        $donnees = $this->asTenant($eleve)->postJson($this->de($evaluation).'/submit', $this->reponses($evaluation))
            ->assertCreated()
            ->json('data');

        $this->assertAucuneNote($donnees);
        $this->assertAucuneNote($donnees['submission']);
    }

    public function test_show_results_faux_la_remise_ne_rend_aucune_note_ecole_klassci(): void
    {
        [$eleve, $evaluation] = $this->eleveKlassciEtEvaluationOuverte(['show_results' => false]);
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk();

        $donnees = $this->asTenant($eleve)->postJson($this->de($evaluation).'/submit', $this->reponses($evaluation))
            ->assertCreated()
            ->json('data');

        $this->assertAucuneNote($donnees);
        $this->assertAucuneNote($donnees['submission']);
    }

    public function test_show_results_vrai_la_remise_rend_la_note(): void
    {
        // Contraste : sans lui, une remise qui masquerait toujours passerait
        // les deux tests ci-dessus.
        [, $eleve, $classe, $maths] = $this->eleveInscrit();
        $evaluation = $this->evaluation($classe, $maths, now()->subMinutes(10), 60, ['show_results' => true]);
        $this->asTenant($eleve)->postJson($this->de($evaluation).'/start')->assertOk();

        $this->asTenant($eleve)->postJson($this->de($evaluation).'/submit', $this->reponses($evaluation))
            ->assertCreated()
            ->assertJsonPath('data.note_sur_20', '20.00');
    }

    // ───────────────────────── harnais

    /**
     * @return array{Institution, User, Classe, Matiere}
     */
    private function eleveInscrit(): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');

        return [$ecole, $eleve, $classe, $this->matiere($ecole, 'Maths')];
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array{User, Evaluation}
     */
    private function eleveKlassciEtEvaluationOuverte(array $surcharges = []): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(), 'klassci_classe_id' => 77, 'klassci_evaluation_id' => 900,
            'is_published' => true, 'status' => 'planifiee', 'is_online' => true,
            'date_evaluation' => now()->subMinutes(10), 'duree_minutes' => 60,
            'allow_retake' => false, 'max_attempts' => 1,
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 77, 'nom' => 'Terminale D']]]);
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'evaluations', 'GET')
                ->andReturn(['data' => [['id' => 900, 'programmation' => ['window' => [
                    'is_open' => true, 'has_started' => true, 'has_ended' => false,
                ]]]]]);
        });
        $this->disableKlassciMiddleware();

        return [$eleve, $evaluation];
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function evaluation(Classe $classe, Matiere $matiere, mixed $date, int $duree, array $surcharges = []): Evaluation
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
        ], $surcharges));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function copie(Evaluation $evaluation, User $eleve, string $statut, array $surcharges = []): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create(array_merge([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => null,
            'attempt' => 1,
            'status' => $statut,
        ], $surcharges));
    }

    /**
     * Une requête « concurrente » insère la même tentative juste AVANT notre
     * insertion (patron d'EvaluationCreationRaceTest). Rend l'id qu'elle a pris.
     *
     * @return \Closure(): int
     */
    private function courseAvant(Evaluation $evaluation, User $eleve, ?int $klassci, string $statut): \Closure
    {
        $id = 0;
        $fait = false;
        DB::beforeExecuting(function (string $query) use (&$fait, &$id, $evaluation, $eleve, $klassci, $statut): void {
            if ($fait || preg_match('/insert into [`"]evaluation_submissions[`"]/i', $query) !== 1) {
                return;
            }
            $fait = true;
            $id = (int) DB::table('evaluation_submissions')->insertGetId([
                'evaluation_id' => $evaluation->getKey(),
                'student_id' => $eleve->getKey(),
                'klassci_etudiant_id' => $klassci,
                'attempt' => 1,
                'status' => $statut,
                'started_at' => now(),
                'institution_id' => $evaluation->institution_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return static function () use (&$id): int {
            return $id;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function reponses(Evaluation $evaluation): array
    {
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();

        return ['answers' => [(string) $question->getKey() => 'a']];
    }

    private function assertAucuneNote(mixed $copie): void
    {
        self::assertIsArray($copie);
        self::assertArrayNotHasKey('score', $copie);
        self::assertArrayNotHasKey('note_sur_20', $copie);
    }

    private function copies(): int
    {
        return DB::table('evaluation_submissions')->count();
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
