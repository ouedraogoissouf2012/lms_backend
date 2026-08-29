<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\SeanceCacheDataBuilder;
use App\Services\Seances\SeanceRestoreGuard;
use App\Services\Visio\SecureVisioRoomIdGenerator;

/**
 * Projette une séance KLASSCI vers le cache local : création ou mise à jour.
 *
 * ## Issue #582 — extraction mécanique depuis `KlassciSeancesSyncService`
 *
 * Le service de sync était à 297 lignes sur les 300 autorisées (§1.1) ;
 * l'orchestration par curseur le faisait déborder. `upsertSeance()` /
 * `createSeance()` sont déplacées ici SANS changement de comportement, ce qui
 * clarifie du même coup les responsabilités : le service de sync *orchestre une
 * passe*, ce collaborateur *projette un payload*.
 *
 * Seule évolution volontaire : la capture d'exception remonte d'un cran, chez
 * l'orchestrateur — lui seul sait qu'une erreur doit aussi souiller le tenant
 * (R5), information qui n'a pas de sens ici.
 *
 * Invariants préservés :
 *  - Isolation tenant (#473) : lookup et écriture scopés explicitement.
 *  - Mapping unique (#474) via {@see SeanceCacheDataBuilder}.
 *  - Restauration d'une séance soft-deletée (#542) via `withTrashed()`.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §5 (méthodes ≤40) · §1.6 D
 */
final class SeanceUpsertService
{
    public function __construct(
        private readonly ClasseSyncService $classeSyncService,
        private readonly NotificationService $notificationService,
        private readonly SeanceCacheDataBuilder $cacheBuilder,
    ) {}

    /**
     * Crée ou met à jour la séance locale.
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $seanceArr
     * @return int|null Identifiant KLASSCI traité, ou null si le payload n'en porte pas
     */
    public function upsert(
        User $teacher,
        string $teacherToken,
        int $institutionId,
        array $matiere,
        array $seanceArr,
        SeanceSyncStats $stats,
    ): ?int {
        $klassciSeanceId = KlassciPayload::toInt($seanceArr['id'] ?? null);
        if ($klassciSeanceId === null) {
            return null;
        }

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

            return $klassciSeanceId;
        }

        $this->create($teacher, $teacherToken, $institutionId, $matiere, $seanceArr, $klassciSeanceId, $cacheData, $stats);

        return $klassciSeanceId;
    }

    /**
     * Crée une nouvelle séance locale (visio activée), synchronise sa classe et
     * notifie l'audience — le tout scopé au tenant de l'enseignant (#473).
     *
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $seanceArr
     * @param  array<string, mixed>  $cacheData
     */
    private function create(
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
