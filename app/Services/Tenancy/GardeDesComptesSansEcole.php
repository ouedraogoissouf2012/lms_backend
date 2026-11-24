<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\Role;
use App\Models\Concerns\InteractsWithRoles;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Annule la contrainte « un compte d'école a une école » tant qu'un compte autre que le
 * supradmin de plateforme vit sans école (#801, PR 3).
 *
 * Elle ne supprime ni ne réaffecte RIEN : un compte n'a pas d'école évidente, l'exploitant
 * tranche. Fusion = déploiement : son message se lit dans le journal de déploiement.
 *
 * Le supradmin se reconnaît au rôle EXACT `supradmin`, comme
 * {@see InteractsWithRoles::isPlatformSupradmin()}. Il se lit par la table des rôles (#1198) :
 * `Role::Supradmin->aliases()` ne contient que ce rôle exact, le même que la contrainte en base.
 */
final class GardeDesComptesSansEcole
{
    public function __construct(private readonly DatabaseManager $db) {}

    /** @throws RuntimeException s'il existe un compte d'école sans école */
    public function exigerAucunCompteDEcoleSansEcole(): void
    {
        $compte = $this->db->connection()->table('users')
            ->whereNull('institution_id')
            ->where(static fn ($role) => $role->whereNull('role')->orWhereNotIn('role', Role::Supradmin->aliases()))
            ->count();

        if ($compte > 0) {
            throw new RuntimeException(
                "Migration #801 (users) annulée AVANT toute modification du schéma :\n"
                ."  - users : {$compte} compte(s) sans établissement qui ne sont pas le supradmin.\n"
                ."Aucun compte n'a été modifié. Lancez `php artisan institutions:audit-orphans`\n"
                .'pour le détail, rattachez ces comptes à leur école, puis relancez le déploiement.',
            );
        }
    }
}
