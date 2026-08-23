<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\Sync\Cursor\SeanceSyncCursorStore;
use App\Services\Seances\Sync\Cursor\TeacherCursorStream;
use Psr\Log\LoggerInterface;

/**
 * Synchronisation des séances KLASSCI vers le cache local — UNE passe budgétée,
 * reprise là où la précédente s'était arrêtée.
 *
 * ## Issue #475 — extrait de `SyncKlassciSeances::handle()` (150 lignes)
 *
 * La triple boucle imbriquée (enseignants → matières → séances) vivait dans le
 * job, non testable en isolation. Elle vit ici, découpée en méthodes à
 * responsabilité unique injectées dans le job.
 *
 * ## Issue #582 — famine et archivage jamais exécuté
 *
 * La boucle repartait TOUJOURS du premier enseignant et s'interrompait au budget
 * de drain (#539). Passé le volume tenant dans 45 s, les enseignants suivants
 * n'étaient jamais atteints — et l'archivage, conditionné à une passe globale
 * complète, ne s'exécutait donc plus jamais. Trois changements :
 *
 *  1. Le parcours est repris par curseur `(institution_id, id)` persisté
 *     ({@see TeacherCursorStream}, {@see SeanceSyncCursorStore}).
 *  2. L'archivage se déclenche à la frontière de tenant, pas à la fin d'une
 *     passe globale ({@see TenantArchiveCoordinator}).
 *  3. Chaque passe journalise sa position et ses compteurs : sans cette
 *     métrique, la famine était indétectable — c'est pourquoi elle a duré.
 *
 * Invariants préservés : isolation tenant (#473), mapping unique (#474), batch
 * matières sans N+1 HTTP (#515), restauration d'une séance soft-deletée (#542).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §5 (méthodes ≤40) · §1.6 D
 * @see .claude/specs/582-seance-sync-cursor/design.md
 */
final class KlassciSeancesSyncService
{
    /** #539 — budget-temps souple d'une passe de sync, sous le `$timeout` du job (55 s). */
    private const SYNC_BUDGET_SECONDS = 45;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly TeacherMatieresResolver $matieresResolver,
        private readonly TeacherCursorStream $teacherStream,
        private readonly SeanceSyncCursorStore $cursorStore,
        private readonly TenantArchiveCoordinator $tenantCoordinator,
        private readonly SeanceSyncStamper $stamper,
        private readonly SeanceUpsertService $upserter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Traite les enseignants à partir de la position persistée, dans la limite
     * du budget, puis clôt la passe (curseur avancé, ou cycle bouclé).
     */
    public function sync(?int $budgetSeconds = null): SeanceSyncStats
    {
        $budgetSeconds ??= self::SYNC_BUDGET_SECONDS;
        $startedAt = microtime(true);

        $stats = new SeanceSyncStats;
        $state = SeanceSyncCycleState::resume($this->cursorStore->load());

        $budgetReached = false;
        foreach ($this->teacherStream->after($state->toPosition()) as $teacher) {
            $institutionId = (int) $teacher->institution_id;

            $this->tenantCoordinator->enterTenant($state, $institutionId, $stats);
            $this->syncTeacher($teacher, $institutionId, $state, $stats);
            $state->advance((int) $teacher->id);

            // #539 — arrêt souple au budget de drain : ne pas monopoliser le worker
            // (jusqu'à 600 s avant) au détriment des jobs `high` (visio temps réel).
            if ((microtime(true) - $startedAt) >= $budgetSeconds) {
                $budgetReached = true;
                break;
            }
        }

        $this->closePass($state, $stats, $budgetReached);
        $this->logPassMetrics($state, $stats);

        return $stats;
    }

    /**
     * Budget atteint ALORS QU'il reste des enseignants → la position est
     * persistée pour que la passe suivante REPRENNE ici, au lieu de réaffamer
     * la queue de population. Sinon (flux épuisé, ou budget tombé pile sur le
     * dernier enseignant) → le dernier tenant est clos et le cycle repart.
     */
    private function closePass(SeanceSyncCycleState $state, SeanceSyncStats $stats, bool $budgetReached): void
    {
        $position = $state->toPosition();

        if ($budgetReached && $this->teacherStream->hasMoreAfter($position)) {
            $this->cursorStore->save($position);

            return;
        }

        $this->tenantCoordinator->closeCycle($state, $stats);
        $this->cursorStore->reset();
    }

    /**
     * R6 — sans position ni compteurs journalisés, une famine reste invisible :
     * les stats globales du job paraissent normales alors que le parcours
     * piétine sur les mêmes enseignants.
     */
    private function logPassMetrics(SeanceSyncCycleState $state, SeanceSyncStats $stats): void
    {
        $this->logger->info('[SyncKlassciSeances] Passe terminée', [
            'teachers_processed' => $stats->teachersChecked,
            'cursor_institution_id' => $state->currentInstitutionId,
            'cursor_user_id' => $state->lastUserId,
            'tenants_completed' => $stats->tenantsCompleted,
            'tenants_archive_skipped' => $stats->tenantsArchiveSkipped,
            'cycle_completed' => $state->cycleCompleted,
            'errors' => $stats->errors,
        ]);
    }

    /**
     * Synchronise les séances d'un enseignant, puis marque comme confirmées
     * celles que KLASSCI a renvoyées. Toute erreur au niveau enseignant est
     * capturée sans interrompre le run, et SOUILLE son tenant : ses séances
     * n'ayant pas pu être confirmées, les archiver serait les perdre.
     */
    private function syncTeacher(User $teacher, int $institutionId, SeanceSyncCycleState $state, SeanceSyncStats $stats): void
    {
        /** @var array<int, int> $confirmedSeanceIds */
        $confirmedSeanceIds = [];

        try {
            $stats->teachersChecked++;

            // Le flux filtre déjà sur klassci_token_encrypted ; ce guard narrower
            // le type pour l'analyse statique et reste défensif (déchiffrement).
            $teacherToken = $teacher->klassci_token;
            if (! is_string($teacherToken)) {
                return;
            }

            $matieres = $this->klassciService->requestWithUserToken($teacherToken, 'matieres', 'GET');
            $matieresList = KlassciPayload::listOfArrays(KlassciPayload::asArray($matieres)['data'] ?? null);

            $this->syncTeacherMatieres($teacher, $teacherToken, $institutionId, $matieresList, $state, $stats, $confirmedSeanceIds);
        } catch (\Exception $e) {
            $stats->errors++;
            $state->taint($institutionId);
            $this->logger->error('Erreur traitement enseignant dans job', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Marquage même en cas d'erreur partielle : ce qui a été confirmé
            // avant la panne l'a bien été, et le tenant est de toute façon
            // souillé (donc non archivé) pour ce cycle.
            $this->stamper->stamp($institutionId, $confirmedSeanceIds, now());
        }
    }

    /**
     * Résout les détails de toutes les matières de l'enseignant en UN SEUL
     * appel batch (élimine le N+1 HTTP — #515, cf. {@see TeacherMatieresResolver}),
     * puis synchronise les séances de chacune des matières résolues.
     *
     * @param  array<int, array<string, mixed>>  $matieresList
     * @param  array<int, int>  $confirmedSeanceIds
     */
    private function syncTeacherMatieres(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matieresList,
        SeanceSyncCycleState $state,
        SeanceSyncStats $stats,
        array &$confirmedSeanceIds,
    ): void {
        $resolution = $this->matieresResolver->resolve($matieresList, $teacherToken);

        foreach ($resolution->failedMatiereIds as $matiereId) {
            // Restaure le signal perdu par le passage au batch : l'ancien code
            // séquentiel comptait chaque échec HTTP de matière via l'exception
            // remontée au catch de syncTeacher(). Le batch fetcher, lui, omet
            // silencieusement les échecs individuels (tolérance partielle) —
            // sans ce comptage explicite, une matière en échec redeviendrait
            // invisible pour la supervision (stats->errors resterait à 0).
            $stats->errors++;
            // #582 — et surtout : les séances de cette matière n'ont pas pu être
            // confirmées. Sans souillure, la clôture du tenant les archiverait.
            $state->taint($institutionId);
            $this->logger->error('Erreur de récupération batch pour une matière — séances potentiellement non synchronisées', [
                'teacher_id' => $teacher->id,
                'matiere_id' => $matiereId,
            ]);
        }

        foreach ($resolution->resolved as $resolvedMatiere) {
            $this->syncMatiereSeances(
                $teacher,
                $teacherToken,
                $institutionId,
                $resolvedMatiere->matiere,
                $resolvedMatiere->details,
                $state,
                $stats,
                $confirmedSeanceIds,
            );
        }
    }

    /**
     * Synchronise les séances d'une matière dont les détails ont déjà été
     * récupérés en batch par `syncTeacherMatieres()` — aucun appel HTTP ici.
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $details
     * @param  array<int, int>  $confirmedSeanceIds
     */
    private function syncMatiereSeances(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        array $details,
        SeanceSyncCycleState $state,
        SeanceSyncStats $stats,
        array &$confirmedSeanceIds,
    ): void {
        $seances = KlassciPayload::listOfArrays(
            KlassciPayload::asArray(KlassciPayload::asArray($details)['data'] ?? null)['seances_programmees'] ?? null
        );
        $stats->seancesFound += count($seances);

        foreach ($seances as $seanceArr) {
            try {
                $confirmedId = $this->upserter->upsert($teacher, $teacherToken, $institutionId, $matiere, $seanceArr, $stats);
                if ($confirmedId !== null) {
                    $confirmedSeanceIds[] = $confirmedId;
                }
            } catch (\Exception $e) {
                $stats->errors++;
                $state->taint($institutionId);
                $this->logger->error('Erreur traitement séance dans job', [
                    'seance_id' => $seanceArr['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
