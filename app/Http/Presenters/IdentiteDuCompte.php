<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\User;

/**
 * Le bloc qui décrit le compte, commun aux trois réponses (connexion locale, connexion KLASSCI,
 * `/auth/me`). Extrait d'`AuthResponsePresenter` (#1536), qui le répétait trois fois et
 * approchait sa limite de 300 lignes.
 *
 * Quatre identifiants, chacun dans son espace, nuls s'ils manquent :
 * - `id`, l'id local ;
 * - `klassci_id`, le numéro de COMPTE KLASSCI (#1409) ;
 * - `klassci_enseignant_id`, l'identité d'ENSEIGNANT KLASSCI (#1409, #873) ;
 * - `id_dans_l_annuaire`, l'id que l'annuaire des enseignants de son école émet pour lui (#1536).
 *   Le front y reconnaît « moi » sans connaître le mode (#805).
 *
 * Présentation seulement : l'id dans l'annuaire est résolu par l'appelant.
 */
final class IdentiteDuCompte
{
    /** @return array{id: mixed, klassci_id: mixed, klassci_enseignant_id: mixed, id_dans_l_annuaire: int|null, name: mixed, email: mixed, role: mixed} */
    public static function de(User $compte, ?int $idDansLAnnuaire): array
    {
        return [
            'id' => $compte->id,
            'klassci_id' => $compte->klassci_id,
            'klassci_enseignant_id' => $compte->klassci_enseignant_id,
            'id_dans_l_annuaire' => $idDansLAnnuaire,
            'name' => $compte->name,
            'email' => $compte->email,
            'role' => $compte->role,
        ];
    }
}
