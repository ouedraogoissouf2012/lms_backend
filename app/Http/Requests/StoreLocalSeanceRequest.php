<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLocalSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titre' => 'required|string|min:3|max:255',
            'date_seance' => 'required|date',
            'matiere_nom' => 'nullable|string|max:191',
            'classe_nom' => 'nullable|string|max:191',
        ];
    }
}
