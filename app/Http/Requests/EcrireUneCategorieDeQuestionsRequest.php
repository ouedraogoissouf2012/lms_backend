<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #1702 (D1) : créer une catégorie de la banque (`matiere_id`, `libelle`), ou la renommer
 * (`libelle` seul). Qui écrit est décidé par `BanqueDeQuestions` : l'enseignant auteur.
 */
final class EcrireUneCategorieDeQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'matiere_id' => $this->isMethod('post') ? 'required|integer|min:1' : 'prohibited',
            'libelle' => 'required|string|min:1|max:150',
        ];
    }

    public function matiere(): int
    {
        $matiere = $this->validated('matiere_id');

        return is_numeric($matiere) ? (int) $matiere : 0;
    }

    public function libelle(): string
    {
        $libelle = $this->validated('libelle');

        return is_string($libelle) ? $libelle : '';
    }
}
