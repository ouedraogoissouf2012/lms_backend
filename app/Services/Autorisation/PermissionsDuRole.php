<?php

declare(strict_types=1);

namespace App\Services\Autorisation;

use App\Enums\Permission;
use App\Enums\Role;

/**
 * Les permissions de chacun des six rôles socles (#1665, lot 1) : EXACTEMENT les droits que les gardes de route
 * leur donnaient (`role:…`, `gestion`, `personnel`). La preuve est la matrice route × rôle figée
 * (tests/Feature/Security/MatriceRouteRole.json), identique avant et après.
 *
 * Les règles héritées, rendues explicites :
 * - le supradmin (gestionnaire de la plateforme) a tout ;
 * - le superAdmin (administrateur d'UNE école) a tout, sauf la plateforme ;
 * - `admin` n'est PAS superAdmin : il n'a ni l'enseignement (les routes `role:enseignant,coordinateur` l'excluaient),
 *   ni la configuration de l'école, ni la diffusion des notifications.
 */
final class PermissionsDuRole
{
    /** `gestion` : coordinateur et administrateurs. */
    private const GESTION = [
        Permission::ComptesGerer,
        Permission::PilotageConsulter,
        Permission::ClassesGerer,
        Permission::FormationsGerer,
        Permission::PresencesDepuisVisio,
    ];

    /** `personnel` : enseignant, coordinateur et administrateurs. */
    private const PERSONNEL = [
        Permission::RapportsPresences,
        Permission::ClassesEleves,
        Permission::ReferentielConsulter,
        Permission::ContenusRediger,
        Permission::ForumModerer,
        Permission::EvaluationsGerer,
        Permission::SeancesGerer,
    ];

    /** `role:enseignant,coordinateur` : celui qui enseigne. */
    private const ENSEIGNEMENT = [
        Permission::EnseignementConsulter,
        Permission::VisioAnimer,
        Permission::ImportsImporter,
        Permission::NotesSaisir,
    ];

    private const ELEVE = [
        Permission::InscriptionRejoindre,
        Permission::SeancesMasquer,
    ];

    /**
     * @return list<Permission>
     */
    public function de(?Role $role): array
    {
        $permissions = match ($role) {
            Role::Supradmin => Permission::cases(),
            Role::SuperAdmin => array_filter(Permission::cases(), static fn (Permission $p): bool => ! $p->estDeLaPlateforme()),
            Role::Admin => [...self::PERSONNEL, ...self::GESTION, Permission::ComptesMotDePasse, Permission::EnseignementConsulter],
            Role::Coordinateur => [
                ...self::PERSONNEL,
                ...self::GESTION,
                ...self::ENSEIGNEMENT,
                Permission::NotificationsDiffuser,
                Permission::KlassciTesterConnexion,
            ],
            Role::Enseignant => [...self::PERSONNEL, ...self::ENSEIGNEMENT, Permission::VisioEnregistrements],
            Role::Etudiant => self::ELEVE,
            null => [],
        };

        return array_values($permissions);
    }
}
