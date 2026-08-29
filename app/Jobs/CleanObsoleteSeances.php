<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithDrainBudget;
use App\Models\Institution;
use App\Models\Seance;
use App\Services\Seances\Sync\SeanceCheckResult;
use App\Services\Seances\Sync\SeanceExistenceBatchChecker;
use App\Services\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Job pour nettoyer les séances obsolètes du LMS
 *
 * Vérifie si les séances locales existent encore dans Klassci.
 * Si une séance n'existe plus dans Klassci, elle est archivée (is_active = false).
 *
 * Ce job doit être exécuté périodiquement (ex: quotidiennement via cron)
 *
 * #516 — vérification PAR INSTITUTION (pas un seul admin arbitraire tous
 * tenants) : chaque institution a son propre backend KLASSCI
 * (`klassci_api_url` diffère réellement entre institutions en prod), donc le
 * tenant courant est positionné via {@see TenantManager::set()} avant chaque
 * lot pour que {@see SeanceExistenceBatchChecker} résolve la BONNE config.
 *
 * ## Isolation de panne PAR INSTITUTION (revue de code #516)
 *
 * L'ancien code isolait les pannes PAR SÉANCE (un `try/catch` par appel HTTP
 * individuel) : une séance en échec ne bloquait jamais les autres. Le nouveau
 * découpage par institution doit préserver la même garantie à SON niveau —
 * une institution dont la config KLASSCI est invalide (URL mal formée que le
 * garde faible `klassci_api_url`/`klassci_api_token` truthy ne filtre pas) ou
 * dont le déchiffrement du token échoue (`DecryptException` sur rotation
 * d'`APP_KEY`) ne doit JAMAIS interrompre le traitement des AUTRES
 * institutions. D'où le `try/catch` PAR institution dans
 * {@see self::processInstitution()}, en plus du `try/finally` garantissant
 * `TenantManager::reset()` (pattern déjà établi dans
 * `app/Jobs/GenerateReportPdf.php` #536).
 */
class CleanObsoleteSeances implements ShouldQueue
{
    use Queueable;
    use InteractsWithDrainBudget;

    /** Nombre max de tentatives — HTTP KLASSCI peut être instable. */
    public int $tries = 3;

    /** #539 — timeout dur borné au budget de drain (55 s) ; arrêt souple avant. */
    public int $timeout = 55;

    /**
     * Backoff progressif HTTP : 1 min, 5 min, 15 min.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(SeanceExistenceBatchChecker $checker, TenantManager $tenantManager, LoggerInterface $logger): void
    {
        $logger->info('🧹 [CleanObsoleteSeances] Début du nettoyage des séances obsolètes');

        // reset() au début (pattern GenerateReportPdf #536) : sur un worker
        // persistant, purge un tenant résiduel d'un job précédent AVANT la
        // requête cross-tenant ci-dessous — sinon le scope global
        // BelongsToInstitution la restreindrait silencieusement au tenant
        // hérité au lieu de porter sur TOUTES les institutions.
        $tenantManager->reset();

        $startedAt = microtime(true);
        $stats = ['checked' => 0, 'archived' => 0, 'errors' => 0, 'institutions_skipped' => 0, 'budget_atteint' => false];

        /** @var Collection<int, int> $institutionIds `institution_id` : clé étrangère NOT NULL (filtrée par `whereNotNull` ci-dessus). */
        $institutionIds = Seance::where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->whereNotNull('institution_id')
            ->distinct()
            ->pluck('institution_id');

        // Préchargement en 1 requête (au lieu d'un `Institution::find()` par
        // institution dans la boucle) : le job qui élimine le N+1 HTTP ne doit
        // pas réintroduire un N+1 SQL à la place.
        $institutions = Institution::whereIn('id', $institutionIds)->get()->keyBy('id');

        try {
            foreach ($institutionIds as $institutionId) {
                $budgetReached = $this->processInstitution($institutionId, $institutions, $checker, $tenantManager, $logger, $stats, $startedAt);

                if ($budgetReached) {
                    $stats['budget_atteint'] = true;
                    $logger->info('[CleanObsoleteSeances] Budget de drain atteint — institutions restantes reportées au run suivant');

                    break;
                }
            }
        } finally {
            // Ne pas fuiter le tenant vers le prochain job du worker (#539/CRITICAL-07).
            $tenantManager->reset();
        }

        $logger->info('✅ [CleanObsoleteSeances] Nettoyage terminé', $stats);
    }

    /**
     * Traite UNE institution : skip propre si config KLASSCI absente (R2),
     * isolation de panne (une institution en échec n'interrompt pas les
     * autres — cf. docblock de classe). Retourne true si le budget-temps a
     * été atteint en cours de route.
     *
     * @param  Collection<int, Institution>  $institutions
     * @param  array{checked: int, archived: int, errors: int, institutions_skipped: int, budget_atteint: bool}  $stats
     */
    private function processInstitution(
        int $institutionId,
        Collection $institutions,
        SeanceExistenceBatchChecker $checker,
        TenantManager $tenantManager,
        LoggerInterface $logger,
        array &$stats,
        float $startedAt,
    ): bool {
        try {
            $institution = $institutions->get($institutionId);

            if (! $institution instanceof Institution || ! $institution->klassci_api_url || ! $institution->klassci_api_token) {
                $stats['institutions_skipped']++;
                $logger->warning('⚠️ [CleanObsoleteSeances] Institution sans configuration KLASSCI exploitable — ignorée', [
                    'institution_id' => $institutionId,
                ]);

                return false;
            }

            return $this->cleanInstitution($institution, $checker, $tenantManager, $logger, $stats, $startedAt);
        } catch (\Throwable $e) {
            $stats['institutions_skipped']++;
            $logger->warning('⚠️ [CleanObsoleteSeances] Institution ignorée suite à une erreur', [
                'institution_id' => $institutionId,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return false;
        }
    }

    /**
     * Vérifie et archive les séances obsolètes d'UNE institution, sous SON
     * tenant. Retourne true si le budget-temps a été atteint en cours de route.
     *
     * @param  array{checked: int, archived: int, errors: int, institutions_skipped: int, budget_atteint: bool}  $stats
     */
    private function cleanInstitution(
        Institution $institution,
        SeanceExistenceBatchChecker $checker,
        TenantManager $tenantManager,
        LoggerInterface $logger,
        array &$stats,
        float $startedAt,
    ): bool {
        $tenantManager->set($institution);
        $budgetReached = false;
        $baseUrl = $institution->klassci_api_url;
        $token = $institution->klassci_api_token;

        // Scope global `institution` (BelongsToInstitution) : borne automatiquement
        // à l'institution courante dès que le tenant est positionné ci-dessus.
        Seance::where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->chunkById($this->drainChunkSize, function (Collection $seances) use ($checker, $baseUrl, $token, $logger, &$stats, &$budgetReached, $startedAt) {
                $this->checkAndArchiveBatch($seances, $checker, $baseUrl, $token, $logger, $stats);

                if ($this->drainBudgetExceeded($startedAt)) {
                    $budgetReached = true;

                    return false;
                }

                return true;
            });

        return $budgetReached;
    }

    /**
     * Vérifie un lot de séances en un appel pool HTTP et archive EN UN SEUL
     * `UPDATE` (pas un `save()` par séance — revue de code #516) celles
     * confirmées supprimées côté KLASSCI (jamais sur une erreur — R4).
     *
     * @param  Collection<int, Seance>  $seances
     * @param  array{checked: int, archived: int, errors: int, institutions_skipped: int, budget_atteint: bool}  $stats
     */
    private function checkAndArchiveBatch(Collection $seances, SeanceExistenceBatchChecker $checker, string $baseUrl, ?string $token, LoggerInterface $logger, array &$stats): void
    {
        $seancesById = $seances->keyBy(fn (Seance $seance) => (int) $seance->klassci_seance_id);

        $results = $checker->checkMany($seancesById->keys()->all(), $baseUrl, $token);

        $toArchive = [];
        foreach ($results as $klassciSeanceId => $result) {
            $stats['checked']++;
            $seance = $seancesById->get($klassciSeanceId);

            if (! $seance instanceof Seance) {
                continue;
            }

            match ($result) {
                SeanceCheckResult::ConfirmedDeleted => $toArchive[] = $seance,
                SeanceCheckResult::Error => $stats['errors']++,
                SeanceCheckResult::Exists => null,
            };
        }

        if ($toArchive !== []) {
            $this->archiveMany($toArchive, $logger, $stats);
        }
    }

    /**
     * @param  array<int, Seance>  $seances
     * @param  array{checked: int, archived: int, errors: int, institutions_skipped: int, budget_atteint: bool}  $stats
     */
    private function archiveMany(array $seances, LoggerInterface $logger, array &$stats): void
    {
        $ids = array_map(static fn (Seance $seance): int => $seance->id, $seances);
        Seance::whereIn('id', $ids)->update(['is_active' => false]);
        $stats['archived'] += count($seances);

        foreach ($seances as $seance) {
            $logger->info('🗑️ [CleanObsoleteSeances] Séance archivée', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'raison' => "N'existe plus dans Klassci",
            ]);
        }
    }

    /**
     * Job échoué après toutes les tentatives.
     */
    public function failed(\Throwable $exception): void
    {
        // Pattern AutoCloseEmptySeances (#209) : failed() est appelée hors
        // container (aucune injection possible) — résolution explicite.
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('[CleanObsoleteSeances] Job failed after all retries', [
            'tries' => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
