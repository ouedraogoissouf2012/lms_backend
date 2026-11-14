<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * « Ma copie » (`GET /evaluations/{id}/my-submission`) suit la spec, dans les deux
 * types d'école.
 *
 * Avant :
 * - chaque question était décrite par un `allOf`, que le validateur du dépôt refuse :
 *   aucun test de contrat ne passait par cette route ;
 * - une copie sans réponse sortait `"answers": []`, un tableau, quand la spec (et le
 *   front, qui déclare la prop `answers` en `Object`) attend un objet.
 *
 * La question de la copie a son composant FERMÉ (`EvaluationQuestionDeLaCopie`), et
 * `answers` sort toujours en objet JSON, `{}` quand il est vide. Prouvé sur le JSON
 * brut : un tableau associatif vide et un tableau vide s'encodent pareil.
 */
final class MaCopieSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_ecole_du_lms_une_copie_avec_reponses_suit_la_spec(): void
    {
        [$eleve, $evaluation, $question] = $this->eleveEtEvaluation($this->ecole());
        $this->copie($evaluation, $eleve, 'soumis', [(string) $question->getKey() => 'a']);

        $reponse = $this->asTenant($eleve)->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');
        $reponse->assertOk()->assertJsonPath('data.answers.'.$question->getKey(), 'a');
    }

    public function test_ecole_du_lms_une_copie_sans_reponse_rend_un_objet_vide(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($this->ecole());
        $this->copie($evaluation, $eleve, 'en_cours', null);

        $reponse = $this->asTenant($eleve)->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');
        $reponse->assertOk();
        self::assertStringContainsString('"answers":{}', (string) $reponse->getContent());
    }

    public function test_ecole_klassci_une_copie_sans_reponse_rend_un_objet_vide_et_suit_la_spec(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        [$eleve, $evaluation] = $this->eleveEtEvaluation($ecole);
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $this->disableKlassciMiddleware();
        $this->copie($evaluation, $eleve, 'soumis', []);

        $reponse = $this->asTenant($eleve)->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');
        self::assertStringContainsString('"answers":{}', (string) $reponse->getContent());
    }

    /** Après le délai, le corrigé est révélé : la réponse suit toujours la spec. */
    public function test_le_corrige_revele_suit_la_spec(): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($this->ecole());
        $this->copie($evaluation, $eleve, 'soumis', [], now()->subDays(8));

        $reponse = $this->asTenant($eleve)->getJson($this->de($evaluation));

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.correction_available', true)
            ->assertJsonPath('data.questions.0.correct_answers', ['a']);
    }

    // ───────────────────────── harnais

    /** @return array{User, Evaluation, EvaluationQuestion} */
    private function eleveEtEvaluation(Institution $ecole): array
    {
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'is_published' => true,
            'show_results' => true,
            'shuffle_questions' => false,
            'status' => 'terminee',
        ]);
        $question = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a'], 'explanation' => 'Parce que.',
        ]);

        return [$eleve, $evaluation, $question];
    }

    /** @param  array<string, mixed>|null  $reponses */
    private function copie(Evaluation $evaluation, User $eleve, string $statut, ?array $reponses, ?\DateTimeInterface $remise = null): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => 1,
            'status' => $statut,
            'answers' => $reponses,
            'score' => $statut === 'en_cours' ? null : '50.00',
            'note_sur_20' => $statut === 'en_cours' ? null : '10.00',
            'submitted_at' => $statut === 'en_cours' ? null : ($remise ?? now()),
        ]);
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey().'/my-submission';
    }
}
