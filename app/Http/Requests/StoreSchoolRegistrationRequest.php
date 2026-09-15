<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la demande publique d'ouverture d'école (#803, ADR-803-01).
 *
 * `authorize()` rend `true` : c'est la SEULE écriture non authentifiée du
 * système, et c'est assumé. La protection ne vient pas d'une autorisation —
 * il n'y a personne à autoriser — mais de ce que l'endpoint a le droit de
 * créer : une ligne inerte, hors multi-tenant, qui ne devient rien tant qu'un
 * humain n'a pas tranché. Le débit est borné au niveau de la route.
 *
 * ## Ce qui n'est PAS validé, et pourquoi
 *
 * `statut`, `institution_id`, `decide_par_user_id` et `motif_refus` n'ont
 * aucune règle : les omettre ici les fait rejeter par `validated()`, et le
 * `$fillable` du modèle les refuse une seconde fois. Une valeur client n'est
 * jamais autoritaire — poster `statut=validee` ne doit pas auto-valider.
 *
 * `slug_souhaite` n'est pas contrôlé contre `institutions.slug` : il n'est
 * jamais réservé, et une vérification d'unicité laisserait croire le contraire.
 * Seule sa FORME est bornée, pour que le supradmin n'ait pas à la corriger.
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
final class StoreSchoolRegistrationRequest extends FormRequest
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
            'nom_demandeur' => ['required', 'string', 'max:191'],
            'email_demandeur' => ['required', 'email', 'max:191'],
            'telephone_demandeur' => ['nullable', 'string', 'max:40'],
            'nom_ecole' => ['required', 'string', 'max:191'],
            'slug_souhaite' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/'],
            'usage_prevu' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'usage_prevu.required' => 'Décrivez brièvement les formations que vous comptez animer.',
            'usage_prevu.min' => 'Quelques mots de plus nous aideront à traiter votre demande.',
            'slug_souhaite.regex' => 'L\'identifiant souhaité ne peut contenir que des lettres minuscules, des chiffres et des tirets.',
        ];
    }
}
