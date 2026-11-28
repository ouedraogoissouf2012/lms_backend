<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EtatDeLaDemande;
use App\Models\DemandeInscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `GET /api/demandes-inscription` (#1659), sur la forme commune des listes (#1647, `ListUsersRequest`). La
 * gestion d'une école ; le supradmin de plateforme, sans école, est refusé.
 */
final class ListerDemandesInscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $acteur = $this->user();

        return $acteur !== null && is_int($acteur->institution_id) && $acteur->isManager();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'type' => ['sometimes', Rule::in(DemandeInscription::TYPES)],
            'etat' => ['sometimes', Rule::enum(EtatDeLaDemande::class)],
            'a_traiter' => ['sometimes', 'boolean'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
