<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

use App\Enums\Role;

/**
 * Résout la colonne `role` d'un fichier d'import, sous plafond (#718).
 *
 * ## Pourquoi un plafond, et pas une simple liste blanche
 *
 * La cellule vient d'un tableur : c'est une donnée d'entrée comme une autre. La
 * consommer telle quelle ferait de la colonne une élévation de privilège en
 * libre-service — il aurait suffi d'écrire « admin » dans Excel. Le plafond est
 * le rôle de CELUI QUI IMPORTE : on ne crée jamais plus permissif que soi.
 *
 * La hiérarchie n'est pas réinventée ici : {@see Role::isMorePermissiveThan}
 * la porte déjà (#121), et la table d'alias FR/EN aussi
 * ({@see Role::tryFromString}). Les dupliquer les ferait diverger.
 *
 * ## Le rôle plateforme est refusé sans condition
 *
 * `supradmin` est cross-tenant (`institution_id` NULL par convention) et ne naît
 * que du seeder. Même politique qu'à la création via KLASSCI (#510) : aucune
 * donnée d'entrée ne le désigne, pas même celle d'un supradmin.
 *
 * Classe pure, sans dépendance — testable unitairement.
 *
 * @see app/Services/Klassci/Auth/KlassciRoleSanitizer.php
 */
final class RoleField
{
    public function resolve(string $raw, Role $ceiling): FieldOutcome
    {
        $declared = trim($raw);

        // Un fichier sans colonne `role` décrit des apprenants : c'est le défaut
        // historique, et le moindre privilège.
        if ($declared === '') {
            return FieldOutcome::accepted(Role::Etudiant->value);
        }

        $role = $this->interpret($declared);

        if ($role === null) {
            return FieldOutcome::rejected(
                'role_inconnu',
                sprintf('Rôle « %s » inconnu. Valeurs acceptées : etudiant, enseignant, coordinateur, admin.', $declared),
            );
        }

        if ($role === Role::Supradmin) {
            return FieldOutcome::rejected(
                'role_plateforme',
                'Le rôle « supradmin » gère la plateforme entière et ne s\'attribue pas par import.',
            );
        }

        if ($role->isMorePermissiveThan($ceiling)) {
            return FieldOutcome::rejected(
                'role_interdit',
                sprintf('Vous ne pouvez pas créer un compte « %s », plus permissif que le vôtre.', $role->value),
            );
        }

        return FieldOutcome::accepted($role->value);
    }

    /**
     * Deux tentatives, et l'ordre compte : `superAdmin` est camelCase dans
     * l'enum, si bien qu'une minusculisation aveugle le rendrait introuvable et
     * le ferait refuser comme rôle inconnu. On essaie donc la graphie telle
     * quelle AVANT de rattraper la casse libre des tableurs.
     */
    private function interpret(string $declared): ?Role
    {
        return Role::tryFromString($declared)
            ?? Role::tryFromString(mb_strtolower($declared));
    }
}
