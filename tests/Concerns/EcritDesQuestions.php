<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\KlassciProxyService;
use Mockery\MockInterface;

/**
 * Un auteur à qui l'école ouvre la création, et les corps de `POST` et `PUT /evaluations`, dans
 * les deux types d'école. Extrait de `OptionsListeDeTextesTest`, pour les suites qui éprouvent
 * la validation des questions.
 *
 * Chaque corps est valide en tout point sauf ce que le test fait varier : sans cela, un 422 ou
 * un 403 venu d'ailleurs (programmation, identité KLASSCI) passerait pour celui qu'on cherche.
 *
 * Requiert `BuildsEcoleIndependante` et `ActsAsTenantUser`.
 */
trait EcritDesQuestions
{
    /** Les champs de programmation valides de l'école en cours. @var array<string, int> */
    private array $programmation = [];

    /** Un auteur à qui l'école ouvre la création : programmé localement, ou enseignant KLASSCI. */
    private function auteurDans(InstitutionMode $mode): User
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');

        if ($mode === InstitutionMode::Standalone) {
            $classe = $this->classe($ecole, '6e A');
            $maths = $this->matiere($ecole, 'Maths');
            $this->programme($classe, $maths, $auteur);
            $this->programmation = ['classe_id' => $classe->id, 'matiere_id' => $maths->id];

            return $auteur;
        }

        // La resynchronisation du profil (#90) n'est pas l'objet de ces tests.
        $this->disableKlassciMiddleware();
        $auteur->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton-enseignant'])->save();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['data' => [['id' => 10, 'nom' => 'Mathématiques']]]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => [['id' => 20, 'libelle' => 'Terminale D']]]);
        });
        $this->programmation = ['klassci_evaluation_id' => 900, 'klassci_classe_id' => 20, 'klassci_matiere_id' => 10];

        return $auteur;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function creation(array $questions): array
    {
        return [
            ...$this->programmation,
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
            'questions' => $questions,
        ];
    }

    /**
     * Une question à options. Sans bonnes réponses données, la première option est la bonne
     * (une liste de textes), sinon `['4']` : le test qui fait varier les options n'éprouve pas
     * les bonnes réponses.
     *
     * @param  list<mixed>|null  $bonnes
     * @return array<string, mixed>
     */
    private function question(mixed $options, ?array $bonnes = null, string $type = 'qcm'): array
    {
        $parDefaut = is_array($options) && array_is_list($options) && isset($options[0]) && is_string($options[0])
            ? [$options[0]]
            : ['4'];

        return [
            'question' => 'Combien font 2 + 2 ?',
            'type' => $type,
            'points' => 2,
            'options' => $options,
            'correct_answers' => $bonnes ?? $parDefaut,
        ];
    }

    private function evaluationDe(User $auteur): Evaluation
    {
        $evaluation = Evaluation::factory()->brouillon()->create([
            'institution_id' => $auteur->institution_id,
            'enseignant_id' => $auteur->id,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'options' => ['3', '4']]);

        return $evaluation;
    }

    /** @return list<mixed> */
    private function optionsDe(Evaluation $evaluation): array
    {
        return EvaluationQuestion::query()->where('evaluation_id', $evaluation->id)->orderBy('id')
            ->get()->map(fn (EvaluationQuestion $question): mixed => $question->options)->all();
    }
}
