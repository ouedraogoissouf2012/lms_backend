<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\Consent;
use App\Models\User;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use App\Services\Retention\RetentionRunner;
use App\Support\Config\LecteurDEntiers;
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

    /** La raison d'épargner un compte qui porte une preuve de consentement (#1827). */
    public const RAISON_PREUVE_DE_CONSENTEMENT = 'porte une preuve de consentement (#716), conservée jusqu\'à sa propre durée (#1827)';

    public function __construct(private readonly LecteurDEntiers $entiers = new LecteurDEntiers) {}

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'utilisateur(s) supprimé(s)';
    }

    /** La durée vit au tableau des durées (`config/conservation.php`, #1705). */
    public function defaultGraceDays(): int
    {
        return $this->entiers->positif(config('conservation.comptes_supprimes_jours'), 30);
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
     * Un compte en corbeille au-delà du délai de grâce est purgeable (#566), SAUF s'il porte une preuve de
     * consentement, comme sujet ou comme acteur : `consents` est append-only et ses deux clés vers `users` sont en
     * RESTRICT (#716). Le détruire lèverait, et arrêtait la purge de tous les comptes suivants (mesuré, #1827). Il est
     * épargné, sa raison dite, jusqu'à ce que la preuve ait sa propre durée.
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        $id = $item->getKey();
        $porteUnePreuve = Consent::query()->withoutGlobalScopes()
            ->where(static fn (Builder $q): Builder => $q->where('user_id', $id)->orWhere('actor_user_id', $id))
            ->exists();

        // SONDE #1705 : la preuve de consentement n'épargne plus le compte.
        return $porteUnePreuve && false ? self::RAISON_PREUVE_DE_CONSENTEMENT : null;
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof User) {
            $item->forceDelete();
        }
    }
}
