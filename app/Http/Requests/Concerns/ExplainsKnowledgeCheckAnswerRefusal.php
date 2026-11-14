<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\KnowledgeCheckCorrectAnswerAmongOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Le 422 d'une bonne réponse de quiz de leçon qui ne désigne aucune option porte
 * son motif (partie 3, ADR-906-01) : `correct_answer_not_among_options`, le même
 * que pour les évaluations (D467), un motif par concept. Les autres erreurs
 * gardent le rendu 422 commun. Partagé par la création et la mise à jour.
 */
trait ExplainsKnowledgeCheckAnswerRefusal
{
    private function refuserLaBonneReponseHorsOptions(Validator $validator): void
    {
        foreach ($validator->failed() as $regles) {
            if (array_key_exists(KnowledgeCheckCorrectAnswerAmongOptions::class, $regles)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => KnowledgeCheckCorrectAnswerAmongOptions::MESSAGE,
                    'reason' => 'correct_answer_not_among_options',
                    'errors' => $validator->errors()->toArray(),
                ], 422));
            }
        }
    }
}
