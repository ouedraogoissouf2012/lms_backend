<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refus d'une demande d'ouverture d'école (#803, ADR-803-02).
 *
 * Le motif est OBLIGATOIRE. Un refus sans raison écrite est ingérable : le
 * demandeur redéposera le même dossier — l'unicité partielle le lui permet
 * explicitement — et l'exploitant n'aura aucun moyen de savoir pourquoi il
 * avait tranché la première fois.
 */
final class RefuseSchoolRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motif_refus' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif_refus.required' => 'Indiquez le motif du refus : le demandeur pourra redéposer un dossier.',
            'motif_refus.min' => 'Un motif de quelques mots évitera un second dossier identique.',
        ];
    }

    public function motif(): string
    {
        $motif = $this->input('motif_refus');

        return is_string($motif) ? $motif : '';
    }
}
