<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Filet de sécurité (#514) : passe à `Failed` les enregistrements bloqués en
 * `Processing` au-delà d'un délai de grâce.
 *
 * ## Pourquoi
 *
 * {@see \App\Services\Visio\Recording\SeanceRecordingControlService::stop()} met
 * un enregistrement en `Processing`, mais le finaliseur
 * {@see \App\Jobs\ProcessSeanceRecordingReady} n'est dispatché que par un webhook
 * fournisseur (Jitsi/Jibri) — non encore construit (cf. #204). Sans lui, un
 * enregistrement arrêté reste `Processing` indéfiniment. Or `Processing` est un
 * statut ACTIF ({@see SeanceRecordingStatus::isActive}) : il conserve
 * `active_lock_key` et BLOQUE le démarrage d'un nouvel enregistrement.
 *
 * Marquer `Failed` reflète un état honnête ET libère le verrou (le hook `saving`
 * du modèle remet `active_lock_key = null` pour un statut non actif).
 *
 * Concurrence : chaque ligne est traitée sous `lockForUpdate` avec re-vérification
 * du statut — si un finaliseur (futur webhook) passait entre-temps, on ne l'écrase
 * pas.
 *
 * @see app/Console/Commands/FailStaleRecordings.php
 */
final class StaleRecordingFailer
{
    private const FAILURE_MESSAGE = 'Aucun fichier reçu du fournisseur d\'enregistrement dans le délai imparti.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return int nombre d'enregistrements passés à Failed
     */
    public function failStaleProcessing(CarbonInterface $cutoff, int $chunkSize): int
    {
        $failed = 0;

        // Périmètre : `Processing` uniquement. C'est le seul statut actif produit
        // après un `stop()` (cf. SeanceRecordingControlService). `Uploading` est
        // l'autre statut actif porteur de verrou, mais AUCUN code ne le pose
        // aujourd'hui (0 écriture) → aucun enregistrement ne peut y rester bloqué.
        // À élargir ici si #204 introduit une phase d'upload sans finaliseur.
        SeanceRecording::withoutGlobalScope('institution')
            ->where('status', SeanceRecordingStatus::Processing)
            ->where('stopped_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($recordings) use ($cutoff, &$failed): void {
                foreach ($recordings as $recording) {
                    if ($this->failOne($recording, $cutoff)) {
                        $failed++;
                    }
                }
            });

        return $failed;
    }

    private function failOne(SeanceRecording $recording, CarbonInterface $cutoff): bool
    {
        return (bool) $this->database->transaction(function () use ($recording, $cutoff): bool {
            $locked = SeanceRecording::withoutGlobalScope('institution')
                ->whereKey($recording->getKey())
                ->lockForUpdate()
                ->first();

            // Re-vérifie sous verrou : un finaliseur a pu passer entre le chunk et ici.
            if ($locked === null
                || $locked->status !== SeanceRecordingStatus::Processing
                || $locked->stopped_at === null
                || ! $locked->stopped_at->lt($cutoff)
            ) {
                return false;
            }

            $locked->update([
                'status' => SeanceRecordingStatus::Failed,
                'error_message' => self::FAILURE_MESSAGE,
                'processed_at' => now(),
            ]);

            $this->logger->warning('Enregistrement bloqué en Processing marqué Failed (#514)', [
                'recording_id' => $locked->id,
                'seance_id' => $locked->seance_id,
                'stopped_at' => $locked->stopped_at->toIso8601String(),
            ]);

            return true;
        });
    }
}
