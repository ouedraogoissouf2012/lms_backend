<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CorrectionRelease;
use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * l'auteur ou un admin de l'école ; ni un coordinateur ni un autre enseignant (403).
 *
 * ## Isolation
 * Une évaluation absente, supprimée ou d'une AUTRE école est introuvable : 404, du même corps
 * dans les trois cas. Un 403 confirmerait qu'elle existe ailleurs. (`publish` rend encore 403 :
 * défaut connu, décrit à part.)
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
        $acteur = $this->user();
        if ($acteur instanceof User && is_int($acteur->institution_id) && ! $this->evaluationDeLEcole($acteur->institution_id)) {
            // Un message fixe : le même corps pour une évaluation absente et pour celle d'une autre école.
            throw new NotFoundHttpException('Évaluation introuvable.');
        }

        return $this->checkEvaluationOwnership();
    }

    /** L'évaluation routée existe-t-elle, non supprimée, dans l'école de l'acteur ? */
    private function evaluationDeLEcole(int $ecole): bool
    {
        return Evaluation::query()->whereKey($this->route('id'))->where('institution_id', $ecole)->exists();
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
