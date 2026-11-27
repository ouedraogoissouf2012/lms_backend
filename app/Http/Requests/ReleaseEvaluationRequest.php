<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CorrectionRelease;
use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `PATCH /api/evaluations/{id}/release` (#1699) : l'enseignant publie les notes
 * (`show_results`) et choisit quand le corrigé s'ouvre (`correction_release`).
 *
 * ## Pourquoi une route à part
 * La modification d'une évaluation est refusée dès la première copie remise
 * (`EvaluationStateService::isLocked`), et c'était la seule voie vers `show_results` : une note
 * cachée restait cachée pour toujours. Publier ne touche ni aux questions ni aux copies : ce
 * geste reste permis sur une évaluation verrouillée.
 *
 * ## Authorization
 * Celle de la modification ({@see ChecksEvaluationOwnership::checkEvaluationOwnership()}) :
 * l'auteur ou un admin de l'école ; ni un coordinateur ni un autre enseignant. Une évaluation
 * d'une autre école rend 403, comme `publish`.
 *
 * ## Saisie
 * Au moins un réglage. Une date (`correction_release_at`) n'existe qu'avec `at_date`, qui
 * l'exige : une date orpheline serait un réglage que personne n'applique.
 */
final class ReleaseEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'show_results' => ['required_without:correction_release', 'boolean'],
            'correction_release' => ['required_without:show_results', Rule::enum(CorrectionRelease::class)],
            'correction_release_at' => [
                'nullable',
                'required_if:correction_release,'.CorrectionRelease::AtDate->value,
                'prohibited_unless:correction_release,'.CorrectionRelease::AtDate->value,
                'date',
            ],
        ];
    }

    /**
     * Les colonnes à écrire. Un autre mode que `at_date` efface la date : elle ne survit pas au
     * mode qui la portait.
     *
     * @return array<string, mixed>
     */
    public function reglage(): array
    {
        $reglage = $this->safe()->only(['show_results', 'correction_release', 'correction_release_at']);
        if (array_key_exists('correction_release', $reglage) && $reglage['correction_release'] !== CorrectionRelease::AtDate->value) {
            $reglage['correction_release_at'] = null;
        }

        return $reglage;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'show_results.required_without' => 'Indiquez la publication des notes ou l\'ouverture du corrigé.',
            'correction_release_at.required_if' => 'Choisissez la date d\'ouverture du corrigé.',
            'correction_release_at.prohibited_unless' => 'Une date d\'ouverture ne vaut que pour le mode « à une date ».',
        ];
    }
}
