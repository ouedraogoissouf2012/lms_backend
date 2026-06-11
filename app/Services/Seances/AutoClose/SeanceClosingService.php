<?php

declare(strict_types=1);

namespace App\Services\Seances\AutoClose;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SeanceClosingService — extrait verbatim de
 * `AutoCloseEmptySeances::closeSeance()` + `determineEndTimestamp()`.
 *
 * Ferme une séance visio dans une transaction unique :
 *   1. Calcule le timestamp de fin selon la raison de fermeture
 *   2. Marque tous les participants encore "connected" comme déconnectés
 *      en calculant leur `duration_minutes`
 *   3. Désactive la séance (`visio_active=false`, `visio_ended_at=...`)
 *
 * Toutes les écritures se font sous transaction : en cas d'erreur, on
 * rollback et on relance l'exception pour que le job Laravel applique
 * sa politique de retry / failure.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility
 */
final class SeanceClosingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ferme la séance donnée pour la raison fournie.
     *
     * @param string $reason Une des raisons connues : `teacher_disconnected`,
     *                       `all_disconnected`, `no_participants`.
     *
     * @throws Throwable Toute exception est re-lancée après rollback pour
     *                   permettre le retry du job appelant.
     */
    public function close(Seance $seance, string $reason, Carbon $now): void
    {
        $this->db->beginTransaction();

        try {
            // Déterminer le timestamp de fin selon la raison
            $endTimestamp = $this->determineEndTimestamp($seance, $reason, $now);

            // Marquer tous les participants encore connectés comme déconnectés
            $stillConnected = ESBTPAttendance::where('seance_id', $seance->id)
                ->where('status', 'connected')
                ->get();

            foreach ($stillConnected as $attendance) {
                $attendance->status = 'disconnected';
                $attendance->left_at = $endTimestamp;

                if ($attendance->joined_at) {
                    $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($endTimestamp);
                }

                $attendance->save();
            }

            // Fermer la séance
            $seance->visio_active = false;
            $seance->visio_ended_at = $endTimestamp;
            $seance->save();

            $this->db->commit();

            $this->logger->info('[AutoCloseEmptySeances] Séance fermée avec succès', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'reason' => $reason,
                'started_at' => $seance->visio_started_at->toDateTimeString(),
                'ended_at' => $endTimestamp->toDateTimeString(),
                'duration_minutes' => $seance->visio_started_at->diffInMinutes($endTimestamp),
                'participants_disconnected' => $stillConnected->count(),
            ]);

        } catch (Throwable $e) {
            $this->db->rollBack();

            $this->logger->error('[AutoCloseEmptySeances] Erreur lors de la fermeture de la séance', [
                'seance_id' => $seance->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Détermine le timestamp de fin approprié selon la raison de fermeture.
     *
     *   - `teacher_disconnected` → `left_at` le plus récent de l'enseignant.
     *   - `all_disconnected`     → `left_at` le plus récent toutes présences confondues.
     *   - `no_participants` (et défaut) → `$now`.
     */
    private function determineEndTimestamp(Seance $seance, string $reason, Carbon $now): Carbon
    {
        switch ($reason) {
            case 'teacher_disconnected':
                // Utiliser le left_at de l'enseignant
                $teacher = ESBTPAttendance::where('seance_id', $seance->id)
                    ->whereHas('user', function ($query) {
                        $query->whereIn('role', ['enseignant', 'teacher']);
                    })
                    ->orderBy('left_at', 'desc')
                    ->first();

                return $teacher && $teacher->left_at ? $teacher->left_at : $now;

            case 'all_disconnected':
                // Utiliser le dernier left_at
                $lastLeftAt = ESBTPAttendance::where('seance_id', $seance->id)
                    ->where('is_observer', false)
                    ->whereNotNull('left_at')
                    ->max('left_at');

                return $lastLeftAt ? Carbon::parse($lastLeftAt) : $now;

            case 'no_participants':
            default:
                // Utiliser maintenant
                return $now;
        }
    }
}
