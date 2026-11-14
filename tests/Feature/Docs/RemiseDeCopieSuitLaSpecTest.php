<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * La réponse 201 de `POST /evaluations/{id}/submit` suit la spec, dans les deux types
 * d'école (lot B des `allOf`).
 *
 * Avant : `data.submission` était décrit par un `allOf` (`EvaluationSubmissionRecord`
 * plus sa relation `evaluation`), que le validateur du dépôt refuse. Désormais : la
 * référence au SEUL composant `EvaluationSubmissionRecord`, qui déclare ses relations
 * chargées.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class RemiseDeCopieSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_ecole_du_lms_la_remise_dans_le_delai_suit_la_spec(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');
        $evaluation = $this->evaluation([
            'institution_id' => $ecole->getKey(), 'classe_id' => $classe->getKey(),
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
        ]);

        $this->assertRemiseSuitLaSpec($eleve, $evaluation);
    }

    public function test_ecole_klassci_la_remise_dans_le_delai_suit_la_spec(): void
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

        $this->assertRemiseSuitLaSpec($eleve, $evaluation);
    }

    private function assertRemiseSuitLaSpec(User $eleve, Evaluation $evaluation): void
    {
        $this->asTenant($eleve)->postJson('/api/evaluations/'.$evaluation->getKey().'/start')->assertOk();
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();

        $reponse = $this->asTenant($eleve)->postJson('/api/evaluations/'.$evaluation->getKey().'/submit', [
            'answers' => [(string) $question->getKey() => 'a'],
        ]);

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/submit', 'post');
        $reponse->assertCreated()->assertJsonPath('data.submission.status', 'soumis');
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
}
