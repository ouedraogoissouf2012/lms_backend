<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Interdit d'assigner à un utilisateur un rôle égal ou supérieur au sien.
 *
 * ## Faille corrigée (audit sécurité 2026-07-02 — OWASP A01 Broken Access Control)
 *
 * {@see \App\Http\Requests\CreateUserRequest} et
 * {@see \App\Http\Requests\UpdateUserRequest} validaient le rôle cible par une
 * simple liste `in:student,enseignant,coordinateur,superAdmin`, alors que leur
 * `authorize()` n'exige que `isCoordinator() || isAdmin()`. Conséquence : un
 * **coordinateur** (permissivité 3) pouvait créer — ou se promouvoir lui-même
 * via `PUT /api/users/{id}` — en `superAdmin`. Pire, la chaîne `'superAdmin'`
 * est normalisée par {@see Role::tryFromString} vers `Role::Supradmin`
 * (permissivité 5), le rôle plateforme cross-tenant le plus élevé. Escalade
 * verticale directe, exploitable par un seul compte interne semi-privilégié.
 *
 * ## Politique appliquée : moindre privilège strict
 *
 * L'acteur ne peut assigner qu'un rôle **strictement moins permissif** que le
 * sien (cf. {@see Role::isMorePermissiveThan}). Corollaires voulus :
 *
 *   - un coordinateur (3) crée etudiant (1) / enseignant (2) — jamais coordinateur+ ;
 *   - personne ne crée un pair de même niveau (pas de coordinateur → coordinateur) ;
 *   - aucun `supradmin` n'est créé par cet endpoint (provisionné par seeder).
 *
 * La règle **échoue de manière fermée** (fail-closed) : acteur absent, rôle
 * acteur inconnu, ou rôle demandé non reconnu → refus. On ne suppose jamais un
 * privilège par défaut.
 *
 * L'acteur est injecté au constructeur (et non lu via une façade) pour rester
 * testable unitairement sans HTTP ni base de données (PRODUCTION_STANDARDS §1.6 D).
 *
 * @see app/Enums/Role.php
 * @see tests/Unit/Rules/AssignableRoleTest.php
 */
final class AssignableRole implements ValidationRule
{
    public function __construct(private readonly ?User $actor)
    {
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $actorRole = $this->actor?->asRoleEnum();

        // Fail-closed : sans identité d'acteur résoluble, aucune assignation.
        if ($actorRole === null) {
            $fail("Vous n'êtes pas autorisé à assigner un rôle.");

            return;
        }

        $requestedRole = is_string($value) ? Role::tryFromString($value) : null;

        if ($requestedRole === null) {
            $fail('Le rôle sélectionné est invalide.');

            return;
        }

        // Moindre privilège : uniquement un rôle STRICTEMENT inférieur au sien.
        if (! $actorRole->isMorePermissiveThan($requestedRole)) {
            $fail('Vous ne pouvez pas assigner un rôle égal ou supérieur au vôtre.');
        }
    }
}
