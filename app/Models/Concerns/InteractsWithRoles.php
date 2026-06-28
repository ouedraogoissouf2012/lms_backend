<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Role;

/**
 * Helpers de rôle du modèle {@see \App\Models\User}.
 *
 * Extrait de `User` (#axe-autorisation) pour regrouper la logique de rôle et
 * tenir la limite PRODUCTION_STANDARDS §5 « Modèles ≤ 150 lignes ». Comportement
 * inchangé — méthodes déplacées verbatim + ajout de `isStaff()`/`isManager()`.
 *
 * `role` est la source d'autorité UNIQUE pour l'autorisation (jamais `klassci_role`,
 * cf. CRITICAL-05). Requiert la propriété `string $role` sur la classe hôte.
 *
 * @see \App\Enums\Role
 */
trait InteractsWithRoles
{
    /**
     * `$this->role` → case enum `Role` (normalise les alias EN/FR, null si
     * inconnu — fail-soft). Issue #121 : source de vérité unique des rôles.
     */
    public function asRoleEnum(): ?Role
    {
        return Role::tryFromString($this->role);
    }

    /** L'utilisateur est-il enseignant ? */
    public function isTeacher(): bool
    {
        return $this->asRoleEnum() === Role::Enseignant;
    }

    /** L'utilisateur est-il coordinateur ? */
    public function isCoordinator(): bool
    {
        return $this->asRoleEnum() === Role::Coordinateur;
    }

    /** L'utilisateur est-il étudiant ? */
    public function isStudent(): bool
    {
        return $this->asRoleEnum() === Role::Etudiant;
    }

    /** Admin au sens large (admin/administrateur/superAdmin/supradmin — cf. Role::isAdmin, #121). */
    public function isAdmin(): bool
    {
        return $this->asRoleEnum()?->isAdmin() ?? false;
    }

    /**
     * Membre du staff (≠ étudiant) : enseignant, coordinateur ou admin
     * (admin inclut supradmin via {@see Role::isAdmin}). Remplace le motif
     * répété `isTeacher() || isCoordinator() || isAdmin()`.
     */
    public function isStaff(): bool
    {
        return $this->isTeacher() || $this->isCoordinator() || $this->isAdmin();
    }

    /**
     * Manager : coordinateur ou admin — staff non enseignant. Remplace le motif
     * répété `isCoordinator() || isAdmin()`.
     */
    public function isManager(): bool
    {
        return $this->isCoordinator() || $this->isAdmin();
    }
}
