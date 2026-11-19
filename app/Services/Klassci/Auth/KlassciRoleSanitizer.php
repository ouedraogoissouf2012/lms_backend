<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Enums\Role;
use Database\Seeders\SupradminSeeder;

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
 * ## Politique (#510, #949)
 *
 * Le rôle KLASSCI est lu à la SEULE table du LMS, {@see Role::tryFromString}, en
 * correspondance exacte (#949 : une seule lecture du rôle) :
 * - le rôle PLATEFORME exact `supradmin` est ramené à l'admin d'INSTITUTION
 *   (`superAdmin`, intra-tenant, jamais cross-tenant) ; le rôle plateforme ne
 *   provient QUE du {@see SupradminSeeder} ;
 * - tout autre rôle connu est rendu sous sa valeur CANONIQUE (`teacher` →
 *   `enseignant`) ;
 * - un rôle hors de la table (variante de casse ou d'espace comprise, comme
 *   `Supradmin`) n'est RIEN : `null`. Il n'est ni promu, ni écrit en base ; le
 *   synchroniseur refuse la connexion d'un compte nouveau, et garde le
 *   `klassci_role` précédent d'un compte existant ;
 * - un rôle ABSENT donne `etudiant`, le moindre privilège.
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
    private const INSTITUTION_ADMIN = Role::SuperAdmin->value;

    /**
     * Le rôle que désigne le payload KLASSCI (`data.user.role`), ou `null`.
     *
     * @param  array<string, mixed>  $klassciUser
     */
    public static function roleRecu(array $klassciUser): ?string
    {
        return isset($klassciUser['role']) && is_string($klassciUser['role']) ? $klassciUser['role'] : null;
    }

    /**
     * Le rôle LMS d'un compte NOUVEAU, ou `null` : un rôle hors de la table n'est
     * jamais écrit, le synchroniseur refuse alors la connexion.
     */
    public function forNewUser(?string $klassciRole): ?string
    {
        if ($klassciRole === null) {
            return Role::Etudiant->value;
        }

        $role = Role::tryFromString($klassciRole);

        return $role === Role::Supradmin ? self::INSTITUTION_ADMIN : $role?->value;
    }

    /**
     * La valeur de `klassci_role` (ce que KLASSCI dit du compte, à titre
     * d'information) : canonique, `etudiant` si KLASSCI n'en donne pas, ou `null`
     * pour un rôle hors de la table, qui n'est jamais écrit.
     */
    public function forKlassciRole(?string $klassciRole): ?string
    {
        return $klassciRole === null ? Role::Etudiant->value : Role::tryFromString($klassciRole)?->value;
    }
}
