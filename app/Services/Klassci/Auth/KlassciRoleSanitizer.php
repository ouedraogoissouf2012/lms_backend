<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Enums\Role;

/**
 * Neutralise l'injection du rôle PLATEFORME (`supradmin`, cross-tenant) via le
 * payload KLASSCI à la CRÉATION d'un utilisateur (#510).
 *
 * ## Pourquoi
 *
 * CRITICAL-05 (#118) gèle le rôle LMS sur l'UPDATE d'un user existant, mais le
 * CREATE écrivait le rôle KLASSCI verbatim ({@see KlassciUserSynchronizer::createNewUser}).
 * Un KLASSCI par-tenant compromis pouvait donc initialiser un compte `supradmin`
 * PLATEFORME (cross-tenant, `institution_id=NULL` par convention), contournant
 * l'isolation multi-tenant.
 *
 * ## Politique
 *
 * Toute désignation du rôle PLATEFORME `supradmin` — y compris ses variantes de
 * casse/espacement que {@see Role::tryFromString} ne reconnaît pas — est ramenée
 * à l'admin d'INSTITUTION (`superAdmin`, intra-tenant, jamais cross-tenant). Le
 * rôle plateforme ne provient QUE du {@see \Database\Seeders\SupradminSeeder}.
 *
 * Tout autre rôle (dont `superAdmin`, l'admin d'institution légitime) est
 * préservé tel quel : aucune régression pour les rôles KLASSCI valides.
 *
 * Classe pure (aucune dépendance) → testable unitairement (§1.6).
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 * @see app/Models/Concerns/InteractsWithRoles.php (isPlatformSupradmin)
 * @see app/Enums/Role.php
 */
final class KlassciRoleSanitizer
{
    /** Rôle d'institution le plus élevé, substitué au rôle plateforme interdit. */
    private const INSTITUTION_ADMIN = 'superAdmin';

    /**
     * Rôle LMS sûr à initialiser pour un nouvel utilisateur découvert via KLASSCI.
     */
    public function forNewUser(?string $klassciRole): string
    {
        // Défaut moindre privilège quand KLASSCI ne fournit aucun rôle.
        $role = $klassciRole ?? Role::Etudiant->value;

        return $this->designatesPlatformRole($role) ? self::INSTITUTION_ADMIN : $role;
    }

    /**
     * Vrai si la chaîne désigne le rôle PLATEFORME `supradmin` (toute casse /
     * espacement). N'inclut PAS `superAdmin` (admin d'institution, intra-tenant),
     * qui reste un rôle légitime préservé.
     */
    private function designatesPlatformRole(string $role): bool
    {
        return strtolower(trim($role)) === Role::Supradmin->value;
    }
}
