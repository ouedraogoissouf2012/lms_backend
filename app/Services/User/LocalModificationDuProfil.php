<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Compte local (école autonome, supradmin de plateforme) : le profil local est le seul (#1667). Seuls les champs
 * PRÉSENTS sont écrits : un `PATCH` qui ne porte que le téléphone ne touche pas au nom. L'audit trace l'acte.
 */
final class LocalModificationDuProfil implements ModificationDuProfil
{
    public const ACTION = 'profile_updated_by_self';

    public function __construct(private readonly AuditLogger $audit) {}

    public function ouvert(User $compte): void {}

    public function modifier(User $compte, array $champs): User
    {
        $compte->forceFill($champs)->save();
        $this->audit->logSecurityEvent(self::ACTION, $compte, ['champs' => array_keys($champs)]);

        return $compte;
    }
}
