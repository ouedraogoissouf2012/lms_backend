<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\User;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use App\Services\Retention\RetentionRunner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purge définitive des comptes mis en corbeille au-delà du délai de grâce
 * (RGPD, #566) — désormais une politique plutôt qu'une commande (#690).
 *
 * Le comportement observable de `users:purge-deleted` est inchangé : mêmes
 * lignes sélectionnées, même événement d'audit `user.purged`, même délai de
 * grâce par défaut. Seul le squelette — simulation, lots, trace — a quitté la
 * commande pour {@see RetentionRunner}.
 *
 * @see app/Services/User/UserDeletionService.php (soft delete réversible)
 */
final class SoftDeletedUsersPolicy implements RetentionPolicy
{
    use NamesTheRow;

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'utilisateur(s) supprimé(s)';
    }

    public function defaultGraceDays(): int
    {
        return 30;
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        // `onlyTrashed()` : seuls les comptes DÉJÀ en corbeille sont concernés.
        // Un compte vivant n'est jamais candidat, quel que soit le délai passé.
        return User::onlyTrashed()->where('deleted_at', '<', $cutoff);
    }

    public function describe(Model $item): string
    {
        if (! $item instanceof User) {
            return 'ligne inattendue';
        }

        $supprimeLe = $item->deleted_at?->toDateString() ?? 'date inconnue';

        return "utilisateur #{$this->identifiant($item)} ({$item->email}), supprimé le {$supprimeLe}";
    }

    public function auditAction(): string
    {
        return 'user.purged';
    }

    /**
     * Aucun refus : un compte en corbeille au-delà du délai de grâce est purgeable
     * sans condition. C'est la promesse RGPD de #566, pas une heuristique.
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        return null;
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof User) {
            $item->forceDelete();
        }
    }
}
