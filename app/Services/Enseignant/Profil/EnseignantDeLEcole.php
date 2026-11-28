<?php

declare(strict_types=1);

namespace App\Services\Enseignant\Profil;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Désigner l'enseignant d'un profil ou de disponibilités (#1666), par son id LOCAL (`users.id`) dans les DEUX volets,
 * jamais par un id KLASSCI. Borné EXPLICITEMENT à l'école de l'acteur. La gestion désigne tout enseignant de son école ;
 * un enseignant, lui-même seulement ; tout autre compte, aucun. Hors de ces cas : 404, sans dire si le compte existe.
 */
final class EnseignantDeLEcole
{
    /** @throws BusinessException 409 `no_institution`, 404 */
    public function designer(int $id, User $acteur): User
    {
        if (! $acteur->isSchoolMember() || ! is_int($acteur->institution_id)) {
            throw new BusinessException('Aucun établissement résolu.', 409, reason: 'no_institution');
        }
        $enseignant = User::query()->withoutGlobalScope('institution')
            ->where('institution_id', $acteur->institution_id)
            ->whereIn('role', Role::Enseignant->aliases())
            ->whereKey($id)
            ->first();
        $permis = $acteur->isManager() || $acteur->isTeacher(); // MUTATION Q1 : un enseignant atteint tout enseignant
        if (! $enseignant instanceof User || ! $permis) {
            throw new BusinessException('Enseignant introuvable', 404);
        }

        return $enseignant;
    }
}
