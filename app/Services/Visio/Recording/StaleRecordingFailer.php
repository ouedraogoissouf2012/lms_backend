<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\SeanceRecording;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

/**
 * Filet de sécurité : referme les enregistrements restés dans un statut ACTIF.
 *
 * ## Les deux façons de rester bloqué
 *
 * `SeanceRecordingStatus::activeValues()` compte trois statuts, et chacun
 * conserve `active_lock_key`, donc **empêche tout nouvel enregistrement de la
 * séance**. Deux d'entre eux peuvent réellement se figer :
 *
 * - **`Processing`** (#514) — posé par {@see SeanceRecordingControlService::stop()}.
 *   Le finaliseur {@see \App\Jobs\ProcessSeanceRecordingReady} n'est dispatché que
 *   par un webhook fournisseur ; sans lui la ligne ne bouge plus.
 * - **`Recording`** (#680) — posé par {@see SeanceRecordingControlService::start()}.
 *   Rien ne le referme si l'enseignant ferme son onglet sans cliquer « Arrêter ».
 *   Constaté en production le 2026-09-02 : ligne ouverte depuis 31 minutes,
 *   verrou tenu, écran affirmant « enregistrement en cours » alors que Jibri
 *   était `IDLE`.
 *
 * `Uploading` est le troisième statut actif, mais **aucun code ne le pose**
 * (0 écriture) : aucun enregistrement ne peut y rester bloqué. À couvrir ici si
 * #204 introduit une phase d'upload sans finaliseur.
 *
 * ## Pourquoi deux seuils distincts, et non un seul
 *
 * `Processing` se date sur `stopped_at` : la capture est finie, seul le
 * traitement traîne, 30 minutes suffisent.
 *
 * `Recording` ne peut PAS utiliser ce seuil : **il couperait tout cours de plus
 * d'une demi-heure**. Et il ne peut pas non plus se dater sur `stopped_at`, qui
 * vaut `NULL` par construction tant qu'on enregistre. Il se date donc sur
 * `seances.visio_ended_at` — un fait métier observable — avec un plafond de
 * durée absolu en dernier recours si la visio elle-même est restée bloquée.
 *
 * Concurrence : chaque ligne est traitée sous `lockForUpdate` avec
 * re-vérification du prédicat — si un finaliseur passait entre-temps, on ne
 * l'écrase pas.
 *
 * @see app/Console/Commands/FailStaleRecordings.php
 */
final class StaleRecordingFailer
{
    private const PROCESSING_FAILURE_MESSAGE = 'Aucun fichier reçu du fournisseur d\'enregistrement dans le délai imparti.';

    private const RECORDING_FAILURE_MESSAGE = 'Enregistrement jamais arrêté : la visioconférence est terminée ou la durée maximale est dépassée.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * #514 — `Processing` arrêté avant `$cutoff` et jamais finalisé.
     *
     * @return int nombre d'enregistrements passés à Failed
     */
    public function failStaleProcessing(CarbonInterface $cutoff, int $chunkSize): int
    {
        $failed = 0;

        SeanceRecording::withoutGlobalScope('institution')
            ->where('status', SeanceRecordingStatus::Processing)
            ->where('stopped_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $recordings) use ($cutoff, &$failed): void {
                foreach ($recordings as $recording) {
                    $stillStale = static fn (SeanceRecording $locked): bool => $locked->status === SeanceRecordingStatus::Processing
                        && $locked->stopped_at !== null
                        && $locked->stopped_at->lt($cutoff);

                    if ($this->failWhenStillStale($recording, self::PROCESSING_FAILURE_MESSAGE, $stillStale)) {
                        $failed++;
                    }
                }
            });

        return $failed;
    }

    /**
     * #680 — `Recording` jamais arrêté, parce que la visio est terminée depuis
     * plus que la grâce, ou parce que la durée maximale est dépassée.
     *
     * La jointure remplace un `whereHas` : elle interroge la table `seances`
     * directement, donc sans que le scope global d'institution s'applique à la
     * sous-requête — cette commande s'exécute sans tenant résolu. Même
     * raisonnement que {@see RoomRecordingResolver}.
     *
     * Une `visio_ended_at` à `NULL` ne satisfait jamais une comparaison SQL :
     * ces lignes ne sont donc atteintes que par le plafond de durée, ce qui est
     * exactement l'intention.
     *
     * @return int nombre d'enregistrements passés à Failed
     */
    public function failStaleRecording(
        CarbonInterface $visioEndedBefore,
        CarbonInterface $startedBefore,
        int $chunkSize,
    ): int {
        $failed = 0;

        SeanceRecording::withoutGlobalScope('institution')
            ->select('seance_recordings.*')
            ->join('seances', 'seances.id', '=', 'seance_recordings.seance_id')
            ->where('seance_recordings.status', SeanceRecordingStatus::Recording)
            ->where(function ($query) use ($visioEndedBefore, $startedBefore): void {
                $query->where('seances.visio_ended_at', '<', $visioEndedBefore)
                    ->orWhere('seance_recordings.started_at', '<', $startedBefore);
            })
            ->orderBy('seance_recordings.id')
            ->chunkById(
                $chunkSize,
                function (Collection $recordings) use ($visioEndedBefore, $startedBefore, &$failed): void {
                    foreach ($recordings as $recording) {
                        if ($this->failStaleRecordingRow($recording, $visioEndedBefore, $startedBefore)) {
                            $failed++;
                        }
                    }
                },
                'seance_recordings.id',
                'id',
            );

        return $failed;
    }

    /**
     * Re-vérifie le prédicat **sous verrou**, en relisant la séance : entre la
     * sélection et ici, la visio a pu redémarrer.
     */
    private function failStaleRecordingRow(
        SeanceRecording $recording,
        CarbonInterface $visioEndedBefore,
        CarbonInterface $startedBefore,
    ): bool {
        $stillStale = static function (SeanceRecording $locked) use ($visioEndedBefore, $startedBefore): bool {
            if ($locked->status !== SeanceRecordingStatus::Recording) {
                return false;
            }

            $seance = $locked->seance()->withoutGlobalScope('institution')->first();
            $visioEndedAt = $seance?->visio_ended_at;

            return ($visioEndedAt !== null && $visioEndedAt->lt($visioEndedBefore))
                || ($locked->started_at !== null && $locked->started_at->lt($startedBefore));
        };

        return $this->failWhenStillStale($recording, self::RECORDING_FAILURE_MESSAGE, $stillStale);
    }

    /**
     * Verrouille, re-vérifie, marque `Failed`. Le hook `saving` du modèle remet
     * `active_lock_key` à `null` pour un statut non actif : le verrou est donc
     * rendu sans que ce service ait à le toucher.
     *
     * @param  callable(SeanceRecording): bool  $stillStale
     */
    private function failWhenStillStale(SeanceRecording $recording, string $message, callable $stillStale): bool
    {
        return (bool) $this->database->transaction(function () use ($recording, $message, $stillStale): bool {
            $locked = SeanceRecording::withoutGlobalScope('institution')
                ->whereKey($recording->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $stillStale($locked)) {
                return false;
            }

            $locked->update([
                'status' => SeanceRecordingStatus::Failed,
                'error_message' => $message,
                'stopped_at' => $locked->stopped_at ?? now(),
                'processed_at' => now(),
            ]);

            $this->logger->warning('Enregistrement bloqué dans un statut actif marqué Failed', [
                'recording_id' => $locked->id,
                'seance_id' => $locked->seance_id,
                'previous_status' => $recording->status->value,
            ]);

            return true;
        });
    }
}
