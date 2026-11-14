<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * C39 : deux remises simultanées DANS le délai ne notent la copie qu'une fois.
 *
 * Avant : la remise lisait la copie `en_cours`, la notait, puis l'écrivait par un
 * `save()` sans condition. Deux `/submit` simultanés (double clic, nouvel essai, deux
 * onglets) la notaient deux fois ; la seconde écriture écrasait les réponses, la note
 * et `submitted_at` de la première, et les deux répondaient 201.
 *
 * Désormais, la note est calculée en mémoire, puis TOUT s'écrit en une seule
 * instruction conditionnelle (`… WHERE status = en_cours`) : une seule transition. La
 * perdante relit la copie et rend son état réel, 409 `evaluation_attempt_conflict`,
 * comme la course perdue hors délai (C28, O1).
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class RemiseDoubleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    public function test_ecole_du_lms_la_remise_qui_perd_la_course_dans_le_delai_dit_le_conflit_sans_rien_ecraser(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $evaluation = $this->evaluation([
            'institution_id' => $ecole->getKey(), 'classe_id' => $classe->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
        ]);

        $this->assertUneSeuleTransition($eleve, $evaluation);
    }

    public function test_ecole_klassci_la_remise_qui_perd_la_course_dans_le_delai_dit_le_conflit_sans_rien_ecraser(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $evaluation = $this->evaluation([
            'institution_id' => $ecole->getKey(), 'klassci_classe_id' => 77, 'klassci_evaluation_id' => 900,
        ]);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 77, 'nom' => 'Terminale D']]]);
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'evaluations', 'GET')
                ->andReturn(['data' => [['id' => 900, 'programmation' => ['window' => [
                    'is_open' => true, 'has_started' => true, 'has_ended' => false,
                ]]]]]);
        });
        $this->disableKlassciMiddleware();

        $this->assertUneSeuleTransition($eleve, $evaluation);
    }

    /** Témoin : sans course, la remise dans le délai note la copie et répond 201. */
    public function test_sans_course_la_remise_note_la_copie(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $evaluation = $this->evaluation([
            'institution_id' => $ecole->getKey(), 'classe_id' => $classe->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
        ]);
        $copie = $this->demarrer($eleve, $evaluation);

        $this->remettre($eleve, $evaluation)->assertCreated()->assertJsonPath('data.note_sur_20', '20.00');

        $apres = DB::table('evaluation_submissions')->where('id', $copie)->first();
        self::assertSame('soumis', $apres?->status);
        self::assertNotNull($apres?->submitted_at);
        self::assertSame(20.0, (float) $apres?->note_sur_20);
    }

    private function assertUneSeuleTransition(User $eleve, Evaluation $evaluation): void
    {
        $copie = $this->demarrer($eleve, $evaluation);
        $gagnante = ['status' => 'soumis', 'answers' => json_encode(['gagnante' => 'x']), 'score' => '99.00', 'note_sur_20' => '19.80', 'submitted_at' => now()->subSecond()];
        $fait = false;
        DB::beforeExecuting(function (string $requete) use (&$fait, $copie, $gagnante): void {
            if ($fait || preg_match('/^update [`"]evaluation_submissions[`"]/i', $requete) !== 1) {
                return;
            }
            $fait = true;
            DB::table('evaluation_submissions')->where('id', $copie)->update($gagnante);
        });

        $this->remettre($eleve, $evaluation)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'evaluation_attempt_conflict');

        self::assertTrue($fait, 'la remise a tenté une écriture');
        $apres = DB::table('evaluation_submissions')->where('id', $copie)->first();
        self::assertSame('soumis', $apres?->status);
        self::assertSame(['gagnante' => 'x'], json_decode((string) $apres?->answers, true), 'les réponses de la gagnante ont été écrasées');
        self::assertSame(19.8, (float) $apres?->note_sur_20, 'la note de la gagnante a été écrasée');
    }

    /** @param  array<string, mixed>  $attributs */
    private function evaluation(array $attributs): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => true, 'is_online' => true, 'status' => 'planifiee', 'show_results' => true,
            'date_evaluation' => now()->subMinutes(10), 'duree_minutes' => 60, 'deadline_at' => null,
            'allow_retake' => false, 'max_attempts' => 1,
        ], $attributs));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function demarrer(User $eleve, Evaluation $evaluation): int
    {
        return (int) $this->asTenant($eleve)->postJson('/api/evaluations/'.$evaluation->getKey().'/start')->assertOk()->json('data.id');
    }

    private function remettre(User $eleve, Evaluation $evaluation): \Illuminate\Testing\TestResponse
    {
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();

        return $this->asTenant($eleve)->postJson('/api/evaluations/'.$evaluation->getKey().'/submit', [
            'answers' => [(string) $question->getKey() => 'a'],
        ]);
    }
}
