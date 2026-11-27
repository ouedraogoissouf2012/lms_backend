<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1698 (C2) : dispenser un élève d'une évaluation (`PUT`), ou lever sa dispense (`DELETE`).
 *
 * Qui : ceux qui lisent les notes de l'évaluation, par la règle unique du domaine
 * ({@see ChecksEvaluationOwnership::checkEvaluationReadAccess()}) : l'admin de l'école,
 * son coordinateur, l'enseignant propriétaire de l'évaluation. Un enseignant tiers est refusé
 * AVANT le contrôleur, donc avant tout appel à KLASSCI.
 *
 * L'élève est désigné comme sur ses copies : `compte_id` (son compte local), sinon
 * `klassci_etudiant_id` (un élève du roster KLASSCI sans compte).
 */
final class DispenserUnEleveRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationReadAccess();
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'compte_id' => 'nullable|integer|min:1|required_without:klassci_etudiant_id',
            'klassci_etudiant_id' => 'nullable|integer|min:1',
            'motif' => 'nullable|string|max:255',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'compte_id.required_without' => 'Désignez l\'élève : son compte, ou son identité KLASSCI.',
            'motif.max' => 'Le motif ne peut pas dépasser 255 caractères.',
        ];
    }

    public function compte(): ?int
    {
        return $this->entier('compte_id');
    }

    public function identiteKlassci(): ?int
    {
        return $this->entier('klassci_etudiant_id');
    }

    public function motif(): ?string
    {
        $motif = $this->validated('motif');

        return is_string($motif) && trim($motif) !== '' ? trim($motif) : null;
    }

    private function entier(string $cle): ?int
    {
        $valeur = $this->validated($cle);

        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
