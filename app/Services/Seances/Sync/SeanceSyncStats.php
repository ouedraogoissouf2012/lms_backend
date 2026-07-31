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
            'errors' => $this->errors,
        ];
    }
}
