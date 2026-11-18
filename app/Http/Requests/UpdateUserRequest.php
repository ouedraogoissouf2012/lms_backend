<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\EcritLeRoleCanonique;
use App\Models\User;
use App\Rules\AssignableRole;
use App\Rules\UniqueEmailInInstitution;
use App\Services\User\GestionDeCompte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ## Sécurité — rôle assignable borné (audit 2026-07-02, OWASP A01)
 * La règle {@see AssignableRole} empêche un acteur de promouvoir un utilisateur
 * (y compris lui-même) vers un rôle égal ou supérieur au sien. Sans elle, un
 * coordinateur pouvait s'auto-promouvoir `superAdmin` via `PUT /api/users/{id}`.
 *
 * ## Compte cible borné (#932)
 * L'acteur ne modifie qu'un compte de rang strictement inférieur au sien, jamais le
 * sien : la règle de cible {@see GestionDeCompte}, la même que la suppression.
 *
 * ## Rôle écrit sous sa valeur canonique (#1246)
 * Un alias reçu (`teacher`…) est remplacé par sa valeur canonique avant la validation :
 * {@see EcritLeRoleCanonique}. Sans `role` dans la requête, rien n'est ajouté.
 *
 * ## Unicité de l'email bornée au tenant (#580, P1 de #563)
 * `'unique:users,email,' . $this->route('user')` cumulait DEUX défauts :
 *   1. la règle `unique:` interroge la table sans scope tenant → contrainte globale à la
 *      plateforme, alors que l'index est `(email, institution_id)` ;
 *   2. `route('user')` renvoie le MODÈLE issu du route-model binding, pas un entier.
 *      `Model::__toString()` le sérialise en JSON, que `ValidationRuleParser` redécoupe
 *      ensuite sur les virgules — toute requête portant un champ `email` partait donc en
 *      SQL invalide (500), en journalisant au passage le nom et l'email de la cible.
 * {@see UniqueEmailInInstitution} borne le contrôle à l'institution de la CIBLE (seule
 * correcte : `institution_id` n'est pas modifiable ici) et exclut sa propre ligne.
 */
final class UpdateUserRequest extends FormRequest
{
    use EcritLeRoleCanonique;

    public function authorize(): bool
    {
        $user = auth()->user();
        $cible = $this->route('user');

        return $user instanceof User && ($user->isCoordinator() || $user->isAdmin())
            && $cible instanceof User && GestionDeCompte::admet($user, $cible);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Le binding est substitué avant la résolution du FormRequest (SubstituteBindings est
        // dans le groupe `api`) : on reçoit un User. Le garde de type reste fail-closed — une
        // cible non résolue fait refuser la règle plutôt que valider contre le mauvais tenant.
        $target = $this->route('user');

        /** @var User|null $actor */
        $actor = $this->user();

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                UniqueEmailInInstitution::forUpdateOf($target instanceof User ? $target : null),
            ],
            'role' => ['sometimes', 'string', new AssignableRole($actor)],
            'klassci_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Pas de clé `email.unique` : le message vient désormais de la règle
            // UniqueEmailInInstitution, qui distingue compte actif et compte supprimé.
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
