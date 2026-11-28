<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\User;

/**
 * Le compte de l'élève (#1705) : son identité, son contact, son rôle et ses dates. Jamais le mot de passe, le jeton de
 * session ni le jeton KLASSCI, ni la copie brute de KLASSCI (`klassci_data`, données de synchronisation).
 */
final class SectionCompte
{
    /** @return array<string, mixed> */
    public function presenter(User $eleve): array
    {
        return [
            'id' => $eleve->getKey(), 'nom' => $eleve->name, 'email' => $eleve->email, 'telephone' => $eleve->phone,
            'role_du_compte' => $eleve->asRoleEnum()?->value, 'identifiant_klassci' => is_numeric($eleve->klassci_id) ? (int) $eleve->klassci_id : null,
            'cree_le' => $eleve->created_at?->toIso8601String(), 'modifie_le' => $eleve->updated_at?->toIso8601String(),
        ];
    }
}
