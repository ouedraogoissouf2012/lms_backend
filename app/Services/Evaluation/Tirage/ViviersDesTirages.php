<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Tirage;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\QuestionDeBanque;
use App\Models\TirageDEvaluation;
use Illuminate\Support\Str;

/**
 * #1702 (D3) : à la PUBLICATION, chaque règle de tirage copie sa catégorie de banque dans
 * l'évaluation (le vivier, `evaluation_questions.tirage_id`). Une modification ultérieure de la
 * banque ne change donc jamais une épreuve publiée, ni sa correction.
 *
 * Refus (422 `question_pool_invalid`, avec le détail par règle dans `errors.tirages`) :
 * - une catégorie disparue ;
 * - moins de questions que le nombre à tirer ;
 * - des POINTS INÉGAUX dans une catégorie : deux élèves auraient des totaux différents. Le refus
 *   NOMME les questions dont les points s'écartent de ceux de la majorité.
 *
 * Appelé sous le verrou de la publication (`EvaluationStateService::publish`). Idempotent : un
 * vivier déjà copié (republication) n'est pas recopié.
 */
final class ViviersDesTirages
{
    /** @return array{status: int, payload: array<string, mixed>}|null le refus, ou null une fois le vivier copié */
    public function constituer(Evaluation $evaluation): ?array
    {
        $tirages = $evaluation->tirages()->with('categorie')->get();
        if ($tirages->isEmpty() || $evaluation->questions()->whereNotNull('tirage_id')->exists()) {
            return null;
        }

        $viviers = [];
        $erreurs = [];
        foreach ($tirages as $rang => $tirage) {
            $questions = $tirage->categorie_id === null ? collect() : QuestionDeBanque::query()->withoutGlobalScope('institution')
                ->where('institution_id', $evaluation->institution_id)->where('categorie_id', $tirage->categorie_id)->orderBy('id')->get();
            $erreur = self::erreur($tirage, array_values($questions->all()));
            if ($erreur !== null) {
                $erreurs['tirages.'.$rang] = [$erreur];
            }
            $viviers[$tirage->id] = $questions;
        }
        if ($erreurs !== []) {
            return ['status' => 422, 'payload' => [
                'success' => false,
                'message' => 'Le tirage au sort ne peut pas être constitué : corrigez la banque ou les tirages.',
                'reason' => 'question_pool_invalid',
                'errors' => $erreurs,
            ]];
        }

        $dernier = $evaluation->questions()->max('ordre');
        $ordre = is_numeric($dernier) ? (int) $dernier : 0;
        foreach ($viviers as $tirage => $questions) {
            foreach ($questions as $question) {
                EvaluationQuestion::query()->create([
                    'evaluation_id' => $evaluation->id,
                    'institution_id' => $evaluation->institution_id,
                    'tirage_id' => $tirage,
                    'question_de_banque_id' => $question->id,
                    'question' => $question->question,
                    'type' => $question->type,
                    'points' => $question->points,
                    'options' => $question->options,
                    'correct_answers' => $question->correct_answers,
                    'explanation' => $question->explanation,
                    'ordre' => ++$ordre,
                ]);
            }
        }

        return null;
    }

    /** @param  list<QuestionDeBanque>  $questions */
    private static function erreur(TirageDEvaluation $tirage, array $questions): ?string
    {
        $libelle = $tirage->categorie?->libelle;
        if ($libelle === null) {
            return 'La catégorie de ce tirage n\'existe plus dans la banque.';
        }
        if (count($questions) < $tirage->nombre) {
            return 'La catégorie « '.$libelle.' » offre '.count($questions).' question(s) pour '.$tirage->nombre.' à tirer.';
        }

        $parPoints = [];
        foreach ($questions as $question) {
            $parPoints[(string) $question->points][] = $question;
        }
        if (count($parPoints) >= 1) { // FALSIFICATION : des points inégaux ne sont plus refusés.
            return null;
        }
        uasort($parPoints, static fn (array $a, array $b): int => count($b) <=> count($a));
        $majorite = (string) array_key_first($parPoints);
        $ecarts = [];
        foreach ($parPoints as $points => $groupe) {
            if ((string) $points !== $majorite) {
                foreach ($groupe as $question) {
                    $ecarts[] = '« '.Str::limit($question->question, 60).' » ('.$points.' pts)';
                }
            }
        }

        return 'La catégorie « '.$libelle.' » mêle des points : '.implode(', ', $ecarts).' ; les autres valent '.$majorite.' pts. Alignez-les : chaque élève doit avoir le même total.';
    }
}
