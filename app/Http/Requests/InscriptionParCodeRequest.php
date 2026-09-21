<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * S'inscrire soi-même avec un code de classe (#846, ADR-803-03).
 *
 * `authorize()` rend `true` : la route est ANONYME par nature — celui qui
 * s'inscrit n'a précisément pas encore de quoi s'authentifier. Le code EST son
 * autorisation, et le débit est borné par un seau nommé.
 *
 * ## Aucune règle `unique` sur l'adresse, délibérément
 *
 * Un `unique:users,email` ferait dire au message de validation qu'un compte
 * existe — et le dirait AVANT même que le code soit vérifié, offrant un oracle
 * d'énumération à qui n'a aucun code. Le service décide, après avoir résolu le
 * code, et sans jamais toucher au compte existant.
 *
 * ## Le mot de passe est CHOISI ici
 *
 * Contrairement à l'import, qui crée des comptes dont personne ne connaît le
 * secret et qui attendent un lien d'activation (ADR-803-02). Celui qui s'inscrit
 * par code est présent : il n'a besoin d'aucun lien.
 */
final class InscriptionParCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:4', 'max:12'],
            'nom' => ['required', 'string', 'max:191'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'max:191', 'confirmed'],
        ];
    }

    /**
     * Une identité JOIGNABLE est exigée, sans imposer laquelle : le produit vit
     * là où le téléphone est plus sûr que le courriel.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->chaine('email') === null && $this->chaine('telephone') === null) {
                $validator->errors()->add('email', 'Une adresse ou un numéro de téléphone est requis.');
            }
        });
    }

    /**
     * @return array{code: string, nom: string, email: ?string, telephone: ?string, password: string}
     */
    public function donnees(): array
    {
        return [
            'code' => (string) $this->string('code'),
            'nom' => (string) $this->string('nom'),
            'email' => $this->chaine('email'),
            'telephone' => $this->chaine('telephone'),
            'password' => (string) $this->string('password'),
        ];
    }

    private function chaine(string $cle): ?string
    {
        $valeur = trim((string) $this->string($cle));

        return $valeur === '' ? null : $valeur;
    }
}
