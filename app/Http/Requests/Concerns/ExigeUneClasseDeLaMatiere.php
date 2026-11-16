<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Matiere\ClassesDeLaMatiere;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * La classe d'une leçon est une classe de sa matière (#1103) : une de celles que
 * la page de la matière propose ({@see ClassesDeLaMatiere}). Sinon, 422 sur
 * `classe_id`, motif `classe_hors_matiere` (un motif par concept, ADR-906-01),
 * et la leçon n'est jamais rangée dans une autre classe.
 *
 * Partagée par la création et l'édition d'une leçon : l'édition suit la règle
 * de la création. Les autres erreurs gardent le rendu 422 commun.
 */
trait ExigeUneClasseDeLaMatiere
{
    private bool $classeHorsMatiere = false;

    private function exigerUneClasseDeLaMatiere(
        Validator $validator,
        ClassesDeLaMatiere $classes,
        ?int $classeId,
        Matiere $matiere,
        User $compte,
    ): void {
        try {
            $admises = $classes->idsPour($matiere, $compte);
        } catch (MissingKlassciTokenException) {
            // Même réponse que la page de la matière, qui ne peut pas non plus
            // calculer ses classes sans jeton.
            throw new HttpResponseException(MissingKlassciTokenException::jsonResponse());
        }

        if ($classeId === null || ! in_array($classeId, $admises, true)) {
            $this->refuserLaClasse($validator);
        }
    }

    private function refuserLaClasse(Validator $validator): void
    {
        $this->classeHorsMatiere = true;
        $validator->errors()->add('classe_id', self::messageClasseHorsMatiere());
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->classeHorsMatiere) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => self::messageClasseHorsMatiere(),
                'reason' => 'classe_hors_matiere',
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private static function messageClasseHorsMatiere(): string
    {
        return "Cette classe n'est pas une classe de la matière.";
    }
}
