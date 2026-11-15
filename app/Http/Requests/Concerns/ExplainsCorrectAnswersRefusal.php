<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\CorrectAnswersAmongOptions;
use App\Rules\KnowledgeCheckCorrectAnswerAmongOptions;
use App\Rules\TrueFalseOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Le 422 d'une bonne réponse qui ne désigne aucune option porte son motif (D466, D467,
 * ADR-906-01) : `correct_answer_not_among_options`, un motif par concept, que le front traduit
 * par un seul texte.
 *
 * L'éditeur du front pouvait produire ce cas (une option renommée après avoir été cochée) : le
 * motif lui permet de le dire en clair, au lieu d'un 422 générique. Les autres erreurs gardent
 * le rendu 422 commun. La SEULE source de ce refus (D602) : partagée par la création et la mise à
 * jour d'une évaluation comme d'un quiz de leçon. Les règles dont l'échec le porte :
 *
 * - une bonne réponse d'évaluation absente des options ({@see CorrectAnswersAmongOptions}) ;
 * - un vrai_faux aux options autres que ['Vrai', 'Faux'] ({@see TrueFalseOptions}, D532) : l'écran
 *   ne propose que ces deux textes ;
 * - une bonne réponse de quiz de leçon qui n'est pas l'indice d'une de ses options
 *   ({@see KnowledgeCheckCorrectAnswerAmongOptions}, partie 3).
 */
trait ExplainsCorrectAnswersRefusal
{
    private function refuserLesBonnesReponsesHorsOptions(Validator $validator): void
    {
        $regles = [CorrectAnswersAmongOptions::class, TrueFalseOptions::class, KnowledgeCheckCorrectAnswerAmongOptions::class];

        foreach ($validator->failed() as $echecs) {
            if (array_intersect($regles, array_keys($echecs)) !== []) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Une bonne réponse doit être une des options proposées.',
                    'reason' => 'correct_answer_not_among_options',
                    'errors' => $validator->errors()->toArray(),
                ], 422));
            }
        }
    }
}
