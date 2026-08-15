<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\SeanceCacheDataBuilder;
use App\Services\Seances\SeanceRestoreGuard;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Psr\Log\LoggerInterface;

/**
 * Synchronisation des séances KLASSCI vers le cache local.
 *
 * ## Issue #475 — extrait de `SyncKlassciSeances::handle()` (150 lignes → orchestrateur fin)
 *
 * `handle()` portait une triple boucle imbriquée (enseignants → matières →
 * séances) + l'archivage, bien au-delà de la limite §5 (≤40 lignes/méthode) et
 * non testable en isolation. Toute cette logique vit désormais ici, découpée en
 * méthodes à responsabilité unique, injectée dans le job (pattern
 * {@see \App\Jobs\AutoCloseEmptySeances} → services-règles).
 *
 * Invariants préservés :
 *  - Isolation tenant (#473) : `institution_id` écrit/scopé explicitement — le
 *    scope global `BelongsToInstitution` est inerte hors requête HTTP.
 *  - Mapping unique (#474) via {@see SeanceCacheDataBuilder}.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §5 (méthodes ≤40) · §1.6 D (DI strict)
 */
final class KlassciSeancesSyncService
{
    /** #539 — budget-temps souple d'une passe de sync, sous le `$timeout` du job (55 s). */
    private const SYNC_BUDGET_SECONDS = 45;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly TeacherMatieresResolver $matieresResolver,
        private readonly StaleSeanceArchiver $archiver,
        private readonly ClasseSyncService $classeSyncService,
        private readonly NotificationService $notificationService,
        private readonly SeanceCacheDataBuilder $cacheBuilder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Synchronise toutes les séances de tous les enseignants liés, puis archive
     * les séances disparues de KLASSCI (tenant par tenant).
     */
    public function sync(?int $budgetSeconds = null): SeanceSyncStats
    {
        $budgetSeconds ??= self::SYNC_BUDGET_SECONDS;
        $startedAt = microtime(true);

        $stats = new SeanceSyncStats;

        /** @var array<int, array<int, int>> $activeIdsByInstitution */
        $activeIdsByInstitution = [];

        $teachers = User::where('role', 'enseignant')
            ->whereNotNull('klassci_token')
            ->get();

        $completePass = true;
        foreach ($teachers as $teacher) {
            $this->syncTeacher($teacher, $stats, $activeIdsByInstitution);

            // #539 — arrêt souple au budget de drain : ne pas monopoliser le worker
            // (jusqu'à 600 s avant) au détriment des jobs `high` (visio temps réel).
            if ((microtime(true) - $startedAt) >= $budgetSeconds) {
                $completePass = false;
                break;
            }
        }

        // #539 — l'archivage EXIGE une passe COMPLÈTE : sur une passe tronquée, les
        // enseignants non atteints n'ont pas alimenté $activeIdsByInstitution, donc
        // leurs séances (même institution) seraient archivées à tort. On reporte au
        // drain suivant. (Reprise par curseur des enseignants = amélioration future
        // tracée dans #539.)
        if ($completePass) {
            $this->archiver->archive($activeIdsByInstitution, $stats);
        } else {
            $this->logger->info('[SyncKlassciSeances] Passe tronquée par le budget de drain — archivage reporté', [
                'teachers_checked' => $stats->teachersChecked,
            ]);
        }

        return $stats;
    }

    /**
     * Synchronise les séances d'un enseignant. Toute erreur au niveau enseignant
     * est capturée et comptée sans interrompre le run global.
     *
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function syncTeacher(User $teacher, SeanceSyncStats $stats, array &$activeIdsByInstitution): void
    {
        try {
            $stats->teachersChecked++;

            // La query filtre déjà whereNotNull('klassci_token') ; ce guard
            // narrower le type pour l'analyse statique et reste défensif.
            $teacherToken = $teacher->klassci_token;
            if (! is_string($teacherToken)) {
                return;
            }

            // Un enseignant non rattaché à une institution ne peut pas être
            // synchronisé de façon isolée (#473) : institution_id est la clé de
            // tenant. On skip défensivement (données incohérentes).
            $institutionId = $teacher->institution_id;
            if ($institutionId === null) {
                return;
            }

            $matieres = $this->klassciService->requestWithUserToken($teacherToken, 'matieres', 'GET');
            $matieresList = KlassciPayload::listOfArrays(KlassciPayload::asArray($matieres)['data'] ?? null);

            $this->syncTeacherMatieres($teacher, $teacherToken, $institutionId, $matieresList, $stats, $activeIdsByInstitution);
        } catch (\Exception $e) {
            $stats->errors++;
            $this->logger->error('Erreur traitement enseignant dans job', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Résout les détails de toutes les matières de l'enseignant en UN SEUL
     * appel batch (élimine le N+1 HTTP — #515, cf. {@see TeacherMatieresResolver}),
     * puis synchronise les séances de chacune des matières résolues.
     *
     * @param  array<int, array<string, mixed>>  $matieresList
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function syncTeacherMatieres(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matieresList,
        SeanceSyncStats $stats,
        array &$activeIdsByInstitution,
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
            $this->logger->error('Erreur de récupération batch pour une matière — séances potentiellement non synchronisées', [
                'teacher_id' => $teacher->id,
                'matiere_id' => $matiereId,
            ]);
        }

        foreach ($resolution->resolved as $resolvedMatiere) {
            $this->syncMatiereSeances($teacher, $teacherToken, $institutionId, $resolvedMatiere->matiere, $resolvedMatiere->details, $stats, $activeIdsByInstitution);
        }
    }

    /**
     * Synchronise les séances d'une matière dont les détails ont déjà été
     * récupérés en batch par `syncTeacherMatieres()` — aucun appel HTTP ici.
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $details
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function syncMatiereSeances(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        array $details,
        SeanceSyncStats $stats,
        array &$activeIdsByInstitution,
    ): void {
        $seances = KlassciPayload::listOfArrays(
            KlassciPayload::asArray(KlassciPayload::asArray($details)['data'] ?? null)['seances_programmees'] ?? null
        );
        $stats->seancesFound += count($seances);

        foreach ($seances as $seanceArr) {
            $this->upsertSeance($teacher, $teacherToken, $institutionId, $matiere, $seanceArr, $stats, $activeIdsByInstitution);
        }
    }

    /**
     * Crée ou met à jour la séance locale. Isolation tenant (#473) : lookup et
     * écriture scopés explicitement par institution (scope global inerte en job).
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $seanceArr
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function upsertSeance(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        array $seanceArr,
        SeanceSyncStats $stats,
        array &$activeIdsByInstitution,
    ): void {
        try {
            $klassciSeanceId = KlassciPayload::toInt($seanceArr['id'] ?? null);
            if ($klassciSeanceId === null) {
                return;
            }

            $activeIdsByInstitution[$institutionId][] = $klassciSeanceId;

            // #542 — withTrashed() : l'unique composite (klassci_seance_id,
            // institution_id) n'est pas filtré sur deleted_at, sans quoi une
            // resync d'une séance soft-deletée violerait l'unique en INSERT.
            $seanceLocal = Seance::withoutGlobalScope('institution')
                ->withTrashed()
                ->where('institution_id', $institutionId)
                ->where('klassci_seance_id', $klassciSeanceId)
                ->first();
            $cacheData = $this->cacheBuilder->build($seanceArr, $matiere, $teacher);

            if ($seanceLocal) {
                SeanceRestoreGuard::restoreIfTrashed($seanceLocal);
                $this->cacheBuilder->applyTo($seanceLocal, $cacheData);

                return;
            }

            $this->createSeance($teacher, $teacherToken, $institutionId, $matiere, $seanceArr, $klassciSeanceId, $cacheData, $stats);
        } catch (\Exception $e) {
            $stats->errors++;
            $this->logger->error('Erreur traitement séance dans job', [
                'seance_id' => $seanceArr['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crée une nouvelle séance locale (visio activée), synchronise sa classe et
     * notifie l'audience — le tout scopé au tenant de l'enseignant (#473).
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $seanceArr
     * @param  array<string, mixed>  $cacheData
     */
    private function createSeance(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        array $seanceArr,
        int $klassciSeanceId,
        array $cacheData,
        SeanceSyncStats $stats,
    ): void {
        $stats->seancesNew++;
        $classeId = KlassciPayload::toInt(KlassciPayload::asArray($seanceArr['classe'] ?? null)['id'] ?? null);
        $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;

        Seance::create($cacheData + [
            'klassci_seance_id' => $klassciSeanceId,
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_status' => 'programmee',
            'visio_room_id' => SecureVisioRoomIdGenerator::make(),
            'visio_active' => false,
            'created_by' => $teacher->id,
        ]);

        if ($classeId !== null) {
            $this->classeSyncService->syncClasseById($classeId, $teacherToken);
        }

        $count = $this->notificationService->notifyVisioScheduled($klassciSeanceId, [
            'institution_id' => $institutionId,
            'klassci_classe_id' => $classeId,
            'klassci_enseignant_id' => $teacher->klassci_id,
            'matiere_nom' => $matiereNom,
            'enseignant_nom' => $teacher->name,
        ]);
        $stats->notificationsSent += $count;
    }
}
