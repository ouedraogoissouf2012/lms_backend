<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;

/**
 * Un élève, une évaluation ouverte, et les VRAIES routes `/start` puis `/submit`, dans les
 * deux types d'école. Extrait de `RemiseDoubleTest` (C39), pour les suites de la remise.
 *
 * École KLASSCI : la classe et la fenêtre viennent du faux KLASSCI (`me/dashboard`,
 * `evaluations`), comme dans `RemiseDoubleTest` avant l'extraction.
 *
 * Requiert `BuildsEcoleIndependante` et `ActsAsTenantUser`.
 */
trait RemetUneCopie
{
    /** @return array{0: User, 1: Evaluation} */
    private function eleveEtEvaluation(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $eleve = $this->membre($ecole, 'etudiant');

        if ($mode === InstitutionMode::Standalone) {
            $classe = $this->classe($ecole, '6e A');
            $this->inscrire($eleve, $classe, 'actif');

            return [$eleve, $this->evaluation([
                'institution_id' => $ecole->getKey(), 'classe_id' => $classe->getKey(),
                'klassci_evaluation_id' => null, 'klassci_classe_id' => null,
            ])];
        }

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

        return [$eleve, $evaluation];
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

    private function remettre(User $eleve, Evaluation $evaluation): TestResponse
    {
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();

        return $this->asTenant($eleve)->postJson('/api/evaluations/'.$evaluation->getKey().'/submit', [
            'answers' => [(string) $question->getKey() => 'a'],
        ]);
    }
}
