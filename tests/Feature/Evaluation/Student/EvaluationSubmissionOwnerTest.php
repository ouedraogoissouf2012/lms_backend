<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Le propriétaire d'une copie est désigné à UN endroit, et lu là où il est écrit.
 *
 * ## Le défaut que ces tests gardent
 *
 * `/start` créait la copie avec `klassci_etudiant_id` et SANS `student_id` ;
 * `/submit` la cherchait ensuite par `student_id` — espace LOCAL — donc ne la
 * trouvait jamais, et en créait une SECONDE avec `attempt` codé en dur à 1. Or
 * `/start` venait de poser `attempt = 1` : le couple
 * `(evaluation_id, klassci_etudiant_id, attempt)` était déjà pris, et l'index
 * unique `eval_sub_unique` refusait l'insertion. La `QueryException` tombait
 * dans un `catch (\Exception)` et ressortait en 500 générique.
 *
 * Conséquence : aucun élève ne pouvait rendre sa copie. Sans copie rendue, le
 * délai, la restitution élève, la restitution enseignant et la synchro KLASSCI
 * n'avaient jamais rien à montrer.
 *
 * ## Le trou de couverture qui l'a laissé passer
 *
 * AUCUN test du dépôt n'enchaînait `/start` puis `/submit`. Le test de succès
 * existant appelle `/submit` seul : il tombe dans la branche « aucune copie
 * trouvée », crée une ligne vierge, et passe en 201. La CI était donc verte sur
 * un chemin qu'aucun élève réel n'emprunte — et ce chemin contourne au passage
 * la fenêtre, le quota et la publication, que `EvaluationAttemptStateService`
 * déclare pourtant centraliser.
 *
 * @see app/Services/Evaluation/Student/EvaluationAttemptStateService.php
 */
final class EvaluationSubmissionOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 5555,
            'klassci_token' => 'student-klassci-token',
        ]);

        // Aucune fenêtre KLASSCI : le contrôle passe sans refus (#499).
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }

    private function publishedEvaluation(bool $allowRetake = true, int $maxAttempts = 3): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
            'klassci_evaluation_id' => 9001,
            'max_attempts' => $maxAttempts,
            'allow_retake' => $allowRetake,
            'is_online' => true,
            'show_results' => true,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    /**
     * @return array<string, mixed>
     */
    private function reponses(Evaluation $evaluation): array
    {
        $question = $evaluation->questions()->firstOrFail();

        return ['answers' => [$question->id => 'A']];
    }

    private function copies(): int
    {
        return EvaluationSubmission::withoutGlobalScopes()->count();
    }

    public function test_le_cycle_start_puis_submit_aboutit(): void
    {
        // LE cas nominal, celui que la suite n'exerçait nulle part.
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $depot = $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation));

        $depot->assertStatus(201)->assertJsonPath('success', true);
        self::assertSame(1, $this->copies(), 'Une seconde copie a ete creee : /submit n a pas retrouve celle de /start.');
        self::assertSame('soumis', EvaluationSubmission::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_la_copie_porte_le_proprietaire_local_des_le_demarrage(): void
    {
        // `student_id` existe depuis 2026_04_30 mais n'était jamais écrit : la
        // colonne locale restait NULL sur toutes les lignes, ce qui rendait
        // inerte toute garde qui l'interrogeait.
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);

        $copie = EvaluationSubmission::withoutGlobalScopes()->firstOrFail();
        self::assertSame($this->student->id, $copie->student_id, 'Le proprietaire local n est pas ecrit au demarrage.');
        self::assertSame(5555, $copie->klassci_etudiant_id, 'Le proprietaire KLASSCI doit rester ecrit : cle de l index unique.');
    }

    public function test_soumettre_sans_avoir_demarre_est_refuse(): void
    {
        // Le contournement : créer la copie dans /submit saute la fenêtre, le
        // quota et la publication — toutes centralisées dans le service de
        // démarrage. Une soumission sans tentative ouverte n'a pas de sens.
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $depot = $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation));

        $depot->assertStatus(409);
        self::assertSame(0, $this->copies(), 'Une copie a ete fabriquee sans passer par le demarrage.');
    }

    public function test_une_copie_deja_rendue_ne_peut_pas_etre_resoumise(): void
    {
        // La garde anti-double-soumission de #347 interrogeait `student_id` :
        // toujours NULL, donc toujours inerte. Le critère honnête est qu'il
        // n'existe plus de tentative OUVERTE.
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation))->assertStatus(201);

        $rejeu = $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation));

        $rejeu->assertStatus(409);
        self::assertSame(1, $this->copies(), 'Le rejeu a produit une copie supplementaire.');
    }

    public function test_une_copie_heritee_sans_miroir_local_reste_celle_de_son_auteur(): void
    {
        // Ce test garde le CHOIX DE LA CLÉ, et lui seul le fait ici : les
        // autres passent tous par /start, qui écrit les deux colonnes — les
        // deux clés y sont donc indiscernables. Une copie ANTÉRIEURE, elle, ne
        // porte que l'identifiant KLASSCI, comme toutes celles déjà en base.
        //
        // Si la propriété se lisait par `student_id`, /start ne reconnaîtrait
        // pas cette copie, en créerait une seconde au même numéro de tentative,
        // et l'index unique la refuserait : le 500 d'origine, reproduit.
        $evaluation = $this->publishedEvaluation();
        $heritee = EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => 5555,
            'student_id' => null,
            'attempt' => 1,
            'status' => 'en_cours',
            'started_at' => now(),
            'institution_id' => $this->institution->id,
        ]);
        Sanctum::actingAs($this->student);

        $reprise = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        // La reprise ne s'annonce pas par une clé `resumed` — le contrôleur ne
        // s'en sert que pour choisir le message. Asserter la clé inventée
        // aurait été le défaut même que ce lot combat : lire une clé que la
        // réponse ne produit pas.
        $reprise->assertStatus(200)->assertJsonPath('message', 'Reprise de la tentative en cours');
        self::assertSame(1, $this->copies(), 'Une seconde copie a ete creee : la copie heritee n a pas ete reconnue.');
        self::assertSame(
            $heritee->id,
            EvaluationSubmission::withoutGlobalScopes()->firstOrFail()->id,
            'La copie reprise n est pas celle qui existait.',
        );
    }

    public function test_la_reprise_autorisee_reste_possible(): void
    {
        // Non-régression : refuser la resoumission ne doit pas interdire une
        // SECONDE tentative légitime, ouverte par un nouveau démarrage.
        $evaluation = $this->publishedEvaluation(allowRetake: true, maxAttempts: 3);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation))->assertStatus(201);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $seconde = $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation));

        $seconde->assertStatus(201);
        self::assertSame(2, $this->copies());
        self::assertSame(
            [1, 2],
            EvaluationSubmission::withoutGlobalScopes()->orderBy('attempt')->pluck('attempt')->all(),
            'Les numeros de tentative ne se suivent pas : attempt a ete recode en dur.',
        );
    }
}
