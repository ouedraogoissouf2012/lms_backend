<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Le lien KLASSCI du compte ({@see User}) : son jeton, stocké chiffré, et la fraîcheur de ses
 * données KLASSCI.
 *
 * Extrait de `User` tel quel (#1231) : le reformatage Pint ajoutait des imports, et le modèle
 * dépassait la limite de 150 lignes. Aucun écouteur ni démarrage : l'ordre des traits de
 * `User` (BelongsToInstitution avant InteractsWithRoles) n'en dépend pas.
 */
trait LienKlassciDuCompte
{
    /** Accessor alias : lecture du token chiffré via l'ancien nom d'attribut. */
    public function getKlassciTokenAttribute(): ?string
    {
        return $this->klassci_token_encrypted;
    }

    /** Mutator alias : écriture du token chiffré via l'ancien nom d'attribut. */
    public function setKlassciTokenAttribute(?string $value): void
    {
        $this->klassci_token_encrypted = $value;
    }

    /** Les données KLASSCI sont-elles fraîches (< 24h) ? Utilisé par EnsureKlassciSync. */
    public function isKlassciDataFresh(): bool
    {
        if (! $this->last_klassci_sync) {
            return false;
        }

        return $this->last_klassci_sync->isAfter(now()->subDay());
    }
}
