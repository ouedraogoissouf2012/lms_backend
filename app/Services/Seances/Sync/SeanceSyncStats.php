<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

/**
 * Accumulateur mutable des compteurs d'un run de synchronisation KLASSCI.
 *
 * Évite de faire circuler un `array<string, int>` par référence à travers toutes
 * les méthodes du {@see KlassciSeancesSyncService} (fragile, non typé). Chaque
 * méthode reçoit l'objet et incrémente les compteurs pertinents.
 */
final class SeanceSyncStats
{
    public int $teachersChecked = 0;

    public int $seancesFound = 0;

    public int $seancesNew = 0;

    public int $notificationsSent = 0;

    public int $seancesArchived = 0;

    /**
     * #582 — Tenants intégralement parcourus dans le cycle et effectivement
     * balayés durant cette passe.
     */
    public int $tenantsCompleted = 0;

    /**
     * #582 — Tenants complets dont l'archivage a été RENONCÉ parce qu'une erreur
     * du cycle laissait leurs séances non confirmées. Un compteur durablement
     * non nul signale une panne KLASSCI persistante, pas un bruit de fond.
     */
    public int $tenantsArchiveSkipped = 0;

    public int $errors = 0;

    /**
     * Vue tableau pour le logging structuré (mêmes clés que l'historique du job).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'teachers_checked' => $this->teachersChecked,
            'seances_found' => $this->seancesFound,
            'seances_new' => $this->seancesNew,
            'notifications_sent' => $this->notificationsSent,
            'seances_archived' => $this->seancesArchived,
            'tenants_completed' => $this->tenantsCompleted,
            'tenants_archive_skipped' => $this->tenantsArchiveSkipped,
            'errors' => $this->errors,
        ];
    }
}
