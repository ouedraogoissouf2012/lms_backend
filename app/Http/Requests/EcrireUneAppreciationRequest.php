<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LitLaPeriodeDuBulletin;
use App\Services\Bulletin\PeriodeDuBulletin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1677 (B1) : `PUT /api/lms/classes/{classe}/appreciations` — une appréciation : `matiere_id` pour
 * celle d'une matière (son enseignant), nul pour la générale et la décision (la gestion). Qui écrit
 * quoi est décidé par `AppreciationsDeBulletin`.
 */
final class EcrireUneAppreciationRequest extends FormRequest
{
    use LitLaPeriodeDuBulletin;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return $this->reglesDeLaPeriode('periode.') + [
            'eleve_id' => 'required|integer|min:1',
            'matiere_id' => 'nullable|integer|min:1',
            'texte' => 'nullable|string|max:2000',
            'decision' => 'nullable|string|max:255',
        ];
    }

    public function periode(): PeriodeDuBulletin
    {
        return $this->laPeriode('periode.');
    }
}
