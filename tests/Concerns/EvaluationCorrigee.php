<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;

/**
 * #1699 : une évaluation passée et CORRIGÉE, dans l'un ou l'autre type d'école.
 *
 * Elle a un enseignant auteur, un élève, et la copie de cet élève, notée 14/20 avec
 * le commentaire « Bon travail. ». Par défaut, les notes ne sont pas publiées
 * (`show_results` faux). L'évaluation a ouvert il y a trois jours, pour une heure :
 * elle est fermée pour tous.
 *
 * École KLASSCI : l'élève porte un jeton ; la fenêtre de l'évaluation 900 est celle que
 * programme le faux KLASSCI ({@see self::klassciProgramme()}).
 *
 * Requiert `BuildsEcoleIndependante` et `ActsAsTenantUser`.
 */
trait EvaluationCorrigee
{
    /**
     * @param  array<string, mixed>  $attributs
     * @return array{0: User, 1: User, 2: Evaluation} l'enseignant, l'élève, l'évaluation
     */
    private function evaluationCorrigee(InstitutionMode $mode, array $attributs = []): array
    {
        $ecole = $this->ecole($mode);
        $enseignant = $this->membre($ecole, 'enseignant');
        $eleve = $this->membre($ecole, 'etudiant');
        $ligne = [
            'institution_id' => $ecole->getKey(), 'enseignant_id' => $enseignant->getKey(),
            'klassci_enseignant_id' => null, 'klassci_matiere_id' => null,
            'is_published' => true, 'is_online' => true, 'status' => 'planifiee', 'show_results' => false,
            'shuffle_questions' => false, 'date_evaluation' => now()->subDays(3), 'duree_minutes' => 60, 'deadline_at' => null,
        ];

        if ($mode === InstitutionMode::Standalone) {
            $classe = $this->classe($ecole, '6e A');
            $this->inscrire($eleve, $classe, 'actif');
            $ligne += ['classe_id' => $classe->getKey(), 'klassci_classe_id' => null, 'klassci_evaluation_id' => null];
        } else {
            $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
            $ligne += ['klassci_classe_id' => 77, 'klassci_evaluation_id' => 900];
        }

        $evaluation = Evaluation::factory()->create(array_merge($ligne, $attributs));
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a'], 'explanation' => 'Parce que.',
        ]);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'institution_id' => $ecole->getKey(),
            'student_id' => $eleve->getKey(), 'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => 1, 'status' => 'corrige', 'answers' => [],
            'score' => '70.00', 'note_sur_20' => '14.00', 'feedback' => 'Bon travail.',
            'submitted_at' => now()->subMinutes(10),
        ]);

        return [$enseignant, $eleve, $evaluation];
    }

    /**
     * La fenêtre que KLASSCI programme pour l'évaluation 900, lue par le jeton de l'élève.
     * `null` : l'évaluation n'a pas de fenêtre chez KLASSCI. Une exception : KLASSCI est injoignable.
     *
     * @param  array<string, mixed>|\Throwable|null  $fenetre
     */
    private function klassciProgramme(array|\Throwable|null $fenetre): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($fenetre): void {
            $appel = $mock->shouldReceive('requestWithUserToken')->with('jeton', 'evaluations', 'GET');
            $fenetre instanceof \Throwable
                ? $appel->andThrow($fenetre)
                : $appel->andReturn(['data' => [['id' => 900, 'programmation' => ['window' => $fenetre]]]]);
        });
    }

    private function maCopie(User $eleve, Evaluation $evaluation): TestResponse
    {
        return $this->asTenant($eleve)->getJson('/api/evaluations/'.$evaluation->getKey().'/my-submission')->assertOk();
    }
}
