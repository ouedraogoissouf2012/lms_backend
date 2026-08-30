<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Source de vérité unique pour les rôles utilisateurs LMS.
 *
 * Issue #121 — Centralise les rôles, leurs alias EN/FR et leur hiérarchie de
 * permissivité. Pattern PHP 8.1 backed enum.
 *
 * ## Alias EN/FR acceptés par `tryFromString`
 *
 * La colonne DB `users.role` peut historiquement contenir n'importe lequel
 * de ces variants (selon la convention KLASSCI au moment du sync). Pour
 * éviter une migration data invasive, l'enum accepte tous les alias
 * connus en lecture, et normalise vers la case canonique FR :
 *
 *  - `'etudiant'`, `'student'` ou `'étudiant'` (accent) → `Role::Etudiant`
 *  - `'enseignant'`   ou `'teacher'`        → `Role::Enseignant`
 *  - `'coordinateur'` ou `'coordinator'`    → `Role::Coordinateur`
 *  - `'admin'`        ou `'administrateur'` → `Role::Admin`
 *  - `'supradmin'`                          → `Role::Supradmin`  (plateforme)
 *  - `'superAdmin'`                         → `Role::SuperAdmin` (etablissement)
 *
 * Tout autre input (`null`, `''`, `'hacker'`, ...) → `null` (fail-soft).
 *
 * ## Surface API
 *
 * L'enum n'expose PAS `isTeacher/isStudent/isCoordinator` — ces helpers
 * restent sur le model `User` pour cohérence avec `User::isAdmin()` (qui est
 * la 4ᵉ méthode du model). L'enum ne fournit que `isAdmin()` car le concept
 * « administratif » a une définition élargie (`Admin` + `Supradmin`).
 *
 * ## Migration (#121)
 *
 * Les helpers `User::isXxx()` passent par cet enum. #521 : `EnsureRole`
 * consomme `tryFromString()` / `aliases()` — plus de table FR/EN locale.
 *
 * @see \App\Models\User::asRoleEnum
 * @see \App\Http\Middleware\EnsureKlassciSync::isEscalationAttempt
 */
enum Role: string
{
    case Etudiant     = 'etudiant';
    case Enseignant   = 'enseignant';
    case Coordinateur = 'coordinateur';
    case Admin        = 'admin';
    /** Administrateur d'UN etablissement — strictement intra-tenant. */
    case SuperAdmin   = 'superAdmin';
    /** Gestionnaire de la PLATEFORME — cross-tenant, institution_id NULL. */
    case Supradmin    = 'supradmin';

    /**
     * Convertit un string brut (DB ou payload KLASSCI) en case enum,
     * en acceptant les alias EN/FR connus. Retourne `null` si la valeur
     * n'est pas reconnue (jamais d'exception — fail-soft).
     */
    public static function tryFromString(?string $value): ?self
    {
        return match ($value) {
            'etudiant', 'student', 'étudiant' => self::Etudiant,
            'enseignant', 'teacher'       => self::Enseignant,
            'coordinateur', 'coordinator' => self::Coordinateur,
            'admin', 'administrateur'     => self::Admin,
            'superAdmin'                  => self::SuperAdmin,
            'supradmin'                   => self::Supradmin,
            default                       => null,
        };
    }

    /**
     * Variantes FR/EN stockées en base pour ce rôle.
     * `superAdmin` n'est PAS un alias de Supradmin ici : c'est l'admin
     * intra-tenant, traité à part par EnsureRole (#102).
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Etudiant => ['etudiant', 'student', 'étudiant'],
            self::Enseignant => ['enseignant', 'teacher'],
            self::Coordinateur => ['coordinateur', 'coordinator'],
            self::Admin => ['admin', 'administrateur', 'administrator'],
            self::SuperAdmin => ['superAdmin'],
            self::Supradmin => ['supradmin'],
        };
    }

    /**
     * Hiérarchie de permissivité (1 = moins permissif, 5 = le plus).
     * Utilisée par `EnsureKlassciSync` pour qualifier les findings de
     * divergence de rôle (cf. issue #34 / PR #118).
     */
    public function permissivity(): int
    {
        return match ($this) {
            self::Etudiant     => 1,
            self::Enseignant   => 2,
            self::Coordinateur => 3,
            self::Admin        => 4,
            self::SuperAdmin   => 5,
            self::Supradmin    => 6,
        };
    }

    /**
     * Vrai pour `Admin`, `SuperAdmin` et `Supradmin` — « admin au sens large ».
     * NE distingue PAS la portee : pour un privilege cross-tenant, utiliser
     * `User::isPlatformSupradmin()`.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin
            || $this === self::SuperAdmin
            || $this === self::Supradmin;
    }

    /**
     * Retourne true ssi le rôle actuel est strictement plus permissif que
     * l'autre. Utilisé par le middleware pour qualifier les tentatives
     * d'escalade silencieuse (cf. issue #34 PR #118).
     */
    public function isMorePermissiveThan(self $other): bool
    {
        return $this->permissivity() > $other->permissivity();
    }
}
