<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use Illuminate\Database\Eloquent\Collection;
use Random\Randomizer;

/**
 * #1702 (D2) : les questions d'une copie, en UN lieu.
 *
 * - Au démarrage, {@see self::presenter()} fixe les questions POSÉES et leur ordre : celui de
 *   l'évaluation, mélangé si elle le demande (`shuffle_questions`). Le hasard est injecté (une
 *   graine en test) ; le résultat est écrit sur la copie (`presentation`) et jamais recalculé.
 * - Ensuite, {@see self::de()} les rend à tous les lecteurs : l'écran de passage, la copie de
 *   l'élève, la notation, la finalité, la correction. Le mélange est donc figé par tentative, et
 *   le tirage (D3) reproductible.
 * - Une copie d'avant la présentation (`presentation` nulle) garde toutes les questions de
 *   l'évaluation, dans leur ordre. Une question supprimée depuis le démarrage disparaît.
 *
 * Le patron des quiz de leçon (`KnowledgeCheckPresentation`, C44).
 */
final class QuestionsDeLaCopie
{
    public function __construct(private readonly Randomizer $hasard) {}

    /**
     * La présentation d'une nouvelle tentative : les questions sans tirage, posées à tous, et,
     * pour chaque règle de tirage (#1702, D3), `nombre` questions tirées au sort dans son vivier ;
     * dans l'ordre de l'évaluation, puis mélangées si elle le demande.
     *
     * @return array{questions: list<int>}
     */
    public function presenter(Evaluation $evaluation): array
    {
        // Dans l'ordre de l'évaluation (`ordre`) : un seul passage les garde dans cet ordre.
        $questions = $evaluation->questions()->get(['id', 'tirage_id']);
        $parTirage = [];
        foreach ($questions as $question) {
            $parTirage[is_numeric($question->tirage_id) ? (int) $question->tirage_id : 0][] = (int) $question->id;
        }
        $retenues = array_fill_keys($parTirage[0] ?? [], true);
        foreach ($evaluation->tirages()->get(['id', 'nombre']) as $tirage) {
            foreach ($this->tirer($parTirage[$tirage->id] ?? [], $tirage->nombre) as $id) {
                $retenues[$id] = true;
            }
        }
        $ids = [];
        foreach ($questions as $question) {
            if (isset($retenues[(int) $question->id])) {
                $ids[] = (int) $question->id;
            }
        }

        return ['questions' => $evaluation->shuffle_questions ? array_values($this->hasard->shuffleArray($ids)) : $ids];
    }

    /**
     * Les questions posées de la copie, dans l'ordre présenté. `$evaluation` évite une requête
     * quand l'appelant tient déjà l'évaluation et ses questions (chargées une fois pour un lot).
     *
     * @return Collection<int, EvaluationQuestion>
     */
    public function de(EvaluationSubmission $copie, ?Evaluation $evaluation = null): Collection
    {
        $evaluation ??= $copie->evaluation;
        /** @var Collection<int, EvaluationQuestion> $toutes */
        $toutes = $evaluation instanceof Evaluation ? $evaluation->questions : new Collection;
        $posees = is_array($copie->presentation) ? ($copie->presentation['questions'] ?? null) : null;
        if (! is_array($posees)) {
            return $toutes->values();
        }

        $parId = $toutes->keyBy(static fn (EvaluationQuestion $question): int => (int) $question->id);
        $rendues = new Collection;
        foreach ($posees as $id) {
            $question = is_numeric($id) ? $parId->get((int) $id) : null;
            if ($question instanceof EvaluationQuestion) {
                $rendues->push($question);
            }
        }

        return $rendues;
    }

    /**
     * `nombre` questions au hasard du vivier (tout le vivier s'il n'en a pas plus).
     *
     * @param  list<int>  $vivier
     * @return list<int>
     */
    private function tirer(array $vivier, int $nombre): array
    {
        return $nombre >= count($vivier) ? $vivier : array_slice(array_values($this->hasard->shuffleArray($vivier)), 0, $nombre);
    }
}
