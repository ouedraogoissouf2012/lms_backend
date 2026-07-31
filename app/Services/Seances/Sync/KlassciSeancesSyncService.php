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
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSyncService $classeSyncService,
        private readonly NotificationService $notificationService,
        private readonly SeanceCacheDataBuilder $cacheBuilder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Synchronise toutes les séances de tous les enseignants liés, puis archive
     * les séances disparues de KLASSCI (tenant par tenant).
     */
    public function sync(): SeanceSyncStats
    {
        $stats = new SeanceSyncStats;

        /** @var array<int, array<int, int>> $activeIdsByInstitution */
        $activeIdsByInstitution = [];

        $teachers = User::where('role', 'enseignant')
            ->whereNotNull('klassci_token')
            ->get();

        foreach ($teachers as $teacher) {
            $this->syncTeacher($teacher, $stats, $activeIdsByInstitution);
        }

        $this->archiveStaleSeances($activeIdsByInstitution, $stats);

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

            foreach ($matieresList as $matiere) {
                $this->syncMatiereSeances($teacher, $teacherToken, $institutionId, $matiere, $stats, $activeIdsByInstitution);
            }
        } catch (\Exception $e) {
            $stats->errors++;
            $this->logger->error('Erreur traitement enseignant dans job', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Récupère et synchronise les séances programmées d'une matière donnée.
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function syncMatiereSeances(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        SeanceSyncStats $stats,
        array &$activeIdsByInstitution,
    ): void {
        $matiereId = KlassciPayload::toInt($matiere['id'] ?? null);
        if ($matiereId === null) {
            return;
        }

        $details = $this->klassciService->requestWithUserToken($teacherToken, "matieres/{$matiereId}", 'GET');
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

            $seanceLocal = Seance::withoutGlobalScope('institution')
                ->where('institution_id', $institutionId)
                ->where('klassci_seance_id', $klassciSeanceId)
                ->first();
            $cacheData = $this->cacheBuilder->build($seanceArr, $matiere, $teacher);

            if ($seanceLocal) {
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

    /**
     * Archive, tenant par tenant, les séances actives absentes du run KLASSCI.
     * N'archive que dans les institutions réellement synchronisées (#473).
     *
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    private function archiveStaleSeances(array $activeIdsByInstitution, SeanceSyncStats $stats): void
    {
        foreach ($activeIdsByInstitution as $institutionId => $activeIds) {
            $archivedSeances = Seance::withoutGlobalScope('institution')
                ->where('institution_id', $institutionId)
                ->where('is_active', true)
                ->whereNotNull('klassci_seance_id')
                ->whereNotIn('klassci_seance_id', $activeIds)
                ->get();

            foreach ($archivedSeances as $seance) {
                $seance->update([
                    'is_active' => false,
                    'archived_at' => now(),
                    'archive_reason' => 'supprimee_klassci',
                ]);
                $stats->seancesArchived++;

                $this->logger->info('Séance archivée (supprimée de Klassci)', [
                    'seance_id' => $seance->id,
                    'klassci_seance_id' => $seance->klassci_seance_id,
                    'institution_id' => $institutionId,
                    'matiere' => $seance->matiere_nom,
                ]);
            }
        }
    }
}
