<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\Role;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;

/**
 * La classe, la matière et le formateur d'un programme, cherchés DANS l'établissement donné
 * (#848). Extraits de `LocalClasseMatiereLinker` (#1653) : rattacher, modifier et retirer une
 * matière d'une classe vérifient les mêmes appartenances, et une seule copie les garde.
 *
 * `withoutGlobalScopes` parce que le scope multi-tenant est fail-OPEN : on borne ici,
 * explicitement, plutôt que de dépendre d'une garde qui se tait quand aucun tenant n'est résolu.
 *
 * Le message ne distingue PAS « n'existe pas » de « appartient à quelqu'un d'autre » : les
 * séparer dirait à un établissement quels identifiants existent chez ses voisins.
 *
 * Deux méthodes jumelles pour la classe et la matière plutôt qu'une générique : celle-ci rendait
 * `mixed`, et PHPStan n9 l'a refusée à juste titre.
 */
final class AppartenancesALEcole
{
    /** @throws BusinessException 404 */
    public function classe(int $id, int $institution): Classe
    {
        $classe = Classe::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('institution_id', $institution)
            ->first();

        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        return $classe;
    }

    /** @throws BusinessException 404 */
    public function matiere(int $id, int $institution): Matiere
    {
        $matiere = Matiere::query()->withoutGlobalScopes()
            ->where('id', $id)
            ->where('institution_id', $institution)
            ->first();

        if (! $matiere instanceof Matiere) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404);
        }

        return $matiere;
    }

    /**
     * Le formateur est FACULTATIF — on compose souvent un programme avant de savoir qui
     * l'assurera. Mais s'il est nommé, il doit être de la maison et enseigner :
     * `classe_matiere.enseignant_id` ne désigne qu'UNE personne par couple, et y laisser entrer
     * un étudiant lui ouvrirait la classe.
     *
     * @throws BusinessException 404
     */
    public function formateur(?int $enseignantId, int $institution): ?int
    {
        if ($enseignantId === null) {
            return null;
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('id', $enseignantId)
            ->where('institution_id', $institution)
            ->first();

        if (! $user instanceof User || Role::tryFromString($user->role) !== Role::Enseignant) {
            throw new BusinessException('Formateur introuvable dans cet établissement.', 404);
        }

        return $enseignantId;
    }
}
