<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Purge PHYSIQUE des utilisateurs soft-deleted au-delà d'un délai de grâce (#566).
 *
 * Contrepartie délibérée du soft delete : sert les demandes d'effacement RGPD, qui
 * doivent être un geste EXPLICITE et tracé. Le `forceDelete()` réactive les cascades
 * FK — c'est l'effacement définitif voulu, jamais un effet de bord.
 *
 * Dry-run PAR DÉFAUT : aucune destruction sans `--force`. NON planifiée dans
 * `routes/console.php` (une destruction n'est jamais un geste passif).
 *
 *   php artisan users:purge-deleted             # dry-run : compte seulement
 *   php artisan users:purge-deleted --force     # purge réelle (forceDelete + cascade)
 *   php artisan users:purge-deleted --days=90   # délai de grâce personnalisé (défaut 30)
 *
 * @see app/Services/User/UserDeletionService.php (soft delete réversible)
 */
final class PurgeSoftDeletedUsers extends Command
{
    /** Délai de grâce par défaut (jours) avant qu'un soft delete devienne purgeable. */
    private const DEFAULT_GRACE_DAYS = 30;

    protected $signature = 'users:purge-deleted
        {--force : Exécute la purge réelle (par défaut : dry-run)}
        {--days=30 : Délai de grâce en jours avant purge définitive}';

    protected $description = 'Purge physiquement les utilisateurs soft-deleted au-delà du délai de grâce (RGPD, #566)';

    public function handle(AuditLogger $audit): int
    {
        $cutoff = now()->subDays($this->graceDays());

        $query = User::onlyTrashed()->where('deleted_at', '<', $cutoff);
        $count = $query->count();

        if (! $this->option('force')) {
            $this->info("[DRY-RUN] {$count} utilisateur(s) soft-deleted avant {$cutoff->toDateString()} seraient purgé(s). Utilisez --force pour exécuter.");

            return self::SUCCESS;
        }

        // chunkById : borne la RAM même sur un large arriéré (§1.6 scalabilité).
        $query->chunkById(100, function ($users) use ($audit): void {
            foreach ($users as $user) {
                // Trace AVANT destruction (le forceDelete efface aussi la cible d'audit).
                $audit->logSecurityEvent('user.purged', $user, [
                    'deleted_at' => $user->deleted_at?->toIso8601String(),
                ]);
                $user->forceDelete();
            }
        });

        $this->info("✓ {$count} utilisateur(s) purgé(s) définitivement (soft-deleted avant {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }

    /**
     * Délai de grâce validé (jours). `option()` retourne mixed → garde `is_numeric`
     * avant cast (niveau 9 interdit `(int) mixed`).
     *
     * Un délai NÉGATIF est un non-sens : il placerait le cutoff dans le futur et
     * purgerait des suppressions récentes. On retombe alors sur le défaut sûr (30 j)
     * plutôt que d'exécuter une purge dangereuse. `--days=0` reste un choix explicite
     * légitime (« purger immédiatement tout ce qui est en corbeille »).
     */
    private function graceDays(): int
    {
        $raw = $this->option('days');
        $days = is_numeric($raw) ? (int) $raw : self::DEFAULT_GRACE_DAYS;

        return $days < 0 ? self::DEFAULT_GRACE_DAYS : $days;
    }
}
