<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Institution;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Purge PHYSIQUE des institutions soft-deletées au-delà d'un délai de grâce (#567).
 *
 * `institution_id` n'a AUCUNE clé étrangère (voir sous-issue « FK manquantes » de
 * #563) : un `forceDelete` d'une institution encore peuplée créerait des orphelins
 * pointant vers un tenant inexistant. La purge REFUSE donc tant que des utilisateurs
 * subsistent — c'est le garde-fou anti-orphelins en attendant les FK.
 *
 * Dry-run PAR DÉFAUT : aucune destruction sans `--force`. NON planifiée.
 *
 *   php artisan institutions:purge-deleted            # dry-run : liste seulement
 *   php artisan institutions:purge-deleted --force    # purge réelle (si 0 fille)
 *   php artisan institutions:purge-deleted --days=90  # délai de grâce (défaut 30)
 *
 * @see app/Services/Institution/InstitutionCrudService.php (soft delete réversible)
 */
final class PurgeSoftDeletedInstitutions extends Command
{
    /** Délai de grâce par défaut (jours) avant qu'un soft delete devienne purgeable. */
    private const DEFAULT_GRACE_DAYS = 30;

    protected $signature = 'institutions:purge-deleted
        {--force : Exécute la purge réelle (par défaut : dry-run)}
        {--days=30 : Délai de grâce en jours avant purge définitive}';

    protected $description = 'Purge physiquement les institutions soft-deletées sans lignes filles (#567)';

    public function handle(AuditLogger $audit): int
    {
        $cutoff = now()->subDays($this->graceDays());
        $institutions = Institution::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();

        if (! $this->option('force')) {
            $this->info("[DRY-RUN] {$institutions->count()} institution(s) soft-deletée(s) avant {$cutoff->toDateString()} éligibles. Utilisez --force pour exécuter (les institutions encore peuplées seront ignorées).");

            return self::SUCCESS;
        }

        $purged = 0;
        foreach ($institutions as $institution) {
            if ($this->hasRemainingChildren($institution)) {
                $this->warn("↷ Institution #{$institution->id} ignorée : des lignes filles subsistent (purge orphelinerait leurs données).");
                continue;
            }

            // Trace AVANT destruction (le forceDelete efface aussi la cible d'audit).
            $audit->logSecurityEvent('institution.purged', $institution, [
                'deleted_at' => $institution->deleted_at?->toIso8601String(),
            ]);
            $institution->forceDelete();
            $purged++;
        }

        $this->info("✓ {$purged} institution(s) purgée(s) définitivement (soft-deletée(s) avant {$cutoff->toDateString()}).");

        return self::SUCCESS;
    }

    /**
     * L'institution a-t-elle encore des lignes filles ? `institution_id` n'ayant
     * pas de FK, on vérifie les principales relations déclarées du tenant avant
     * une destruction physique — refus si l'une existe (anti-orphelins).
     */
    private function hasRemainingChildren(Institution $institution): bool
    {
        return $institution->users()->withoutGlobalScope('institution')->exists()
            || $institution->classes()->withoutGlobalScope('institution')->exists()
            || $institution->lessons()->withoutGlobalScope('institution')->exists()
            || $institution->evaluations()->withoutGlobalScope('institution')->exists();
    }

    /**
     * Délai de grâce validé. `option()` retourne mixed → garde `is_numeric` avant
     * cast (niveau 9 interdit `(int) mixed`). Un délai négatif est un non-sens
     * (cutoff dans le futur → purge de suppressions récentes) → défaut sûr.
     */
    private function graceDays(): int
    {
        $raw = $this->option('days');
        $days = is_numeric($raw) ? (int) $raw : self::DEFAULT_GRACE_DAYS;

        return $days < 0 ? self::DEFAULT_GRACE_DAYS : $days;
    }
}
