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
 *  - `'etudiant'`     ou `'student'`        → `Role::Etudiant`
 *  - `'enseignant'`   ou `'teacher'`        → `Role::Enseignant`
 *  - `'coordinateur'` ou `'coordinator'`    → `Role::Coordinateur`
 *  - `'admin'`        ou `'administrateur'` → `Role::Admin`
 *  - `'supradmin'`    ou `'superAdmin'`     → `Role::Supradmin`
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
 * ## Migration progressive (issue #121)
 *
 *  - **PR #121a** : 2 sites racine refactorés (User helpers + EnsureKlassciSync).
 *    77 sites disséminés inchangés — fonctionnent via les méthodes
 *    `User::isXxx()` qui sont désormais propulsées par l'enum.
 *  - **PR #121b (follow-up)** : migration des 77 sites disséminés vers
 *    `$user->isXxx()` ou `$user->asRoleEnum() === Role::X`.
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
    case Supradmin    = 'supradmin';

    /**
     * Convertit un string brut (DB ou payload KLASSCI) en case enum,
     * en acceptant les alias EN/FR connus. Retourne `null` si la valeur
     * n'est pas reconnue (jamais d'exception — fail-soft).
     */
    public static function tryFromString(?string $value): ?self
    {
        return match ($value) {
            'etudiant', 'student'         => self::Etudiant,
            'enseignant', 'teacher'       => self::Enseignant,
            'coordinateur', 'coordinator' => self::Coordinateur,
            'admin', 'administrateur'     => self::Admin,
            'supradmin', 'superAdmin'     => self::Supradmin,
            default                       => null,
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
            self::Supradmin    => 5,
        };
    }

    /**
     * Retourne true pour `Admin` et `Supradmin`, false pour les 3 autres.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin || $this === self::Supradmin;
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
