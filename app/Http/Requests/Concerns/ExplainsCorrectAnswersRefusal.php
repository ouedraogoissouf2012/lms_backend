<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\CorrectAnswersAmongOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Le 422 d'une bonne réponse absente des options porte son motif (D466, D467, ADR-906-01).
 *
 * L'éditeur du front pouvait produire ce cas (une option renommée après avoir été cochée) : le
 * motif `correct_answer_not_among_options` lui permet de le dire en clair, au lieu d'un 422
 * générique. Les autres erreurs gardent le rendu 422 commun. Partagé par la création et la
 * mise à jour.
 */
trait ExplainsCorrectAnswersRefusal
{
    private function refuserLesBonnesReponsesHorsOptions(Validator $validator): void
    {
        foreach ($validator->failed() as $regles) {
            if (array_key_exists(CorrectAnswersAmongOptions::class, $regles)) {
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
