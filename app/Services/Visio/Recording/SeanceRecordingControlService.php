<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;

final class SeanceRecordingControlService
{
    /**
     * #706 — refus explicite quand la plateforme est saturée. Un message sec
     * vaut mieux qu'une ligne `Recording` vouée à l'échec silencieux : c'est
     * précisément la perte que l'enseignant ne découvrait qu'après son cours.
     */
    private const CAPACITY_MESSAGE = 'Un autre enregistrement est en cours sur la plateforme. Reessayez dans quelques minutes.';

    public function __construct(
        private readonly RecordingStateCache $stateCache,
        private readonly SeanceRecordingAccessService $access,
        private readonly AuditLogger $audit,
        private readonly RecordingConsentGuard $consents,
        private readonly RecordingCapacityGuard $capacity,
    ) {}

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function start(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canControl($seance, $user)) {
            return $this->fail(403, 'Acces reserve a l enseignant proprietaire');
        }

        if (! $this->consents->allowsStart($seance, $user)) {
            return $this->fail(422, 'Le consentement a la captation doit etre recueilli avant tout enregistrement.');
        }

        $recording = $this->latestRecording($seance);
        $created = false;
        if ($recording === null || ! $recording->status->isActive()) {
            $claimed = $this->capacity->claimSlotFor($seance, fn (): array => $this->openRecording($seance));

            if ($claimed === null) {
                return $this->fail(409, self::CAPACITY_MESSAGE);
            }

            [$recording, $created] = $claimed;
        }

        $this->stateCache->remember($seance, $recording);
        if ($created) {
            $this->auditRecording('visio_recording_start', $seance, $recording);
        }

        return $this->ok($recording->toRecordingPayload());
    }

    /**
     * Ouvre la ligne d'enregistrement, une fois le créneau réservé.
     *
     * La violation d'unicité signifie qu'une requête concurrente a ouvert
     * l'enregistrement de CETTE séance entre-temps : on récupère la sienne
     * plutôt que d'échouer — et `created` repasse à faux pour ne pas journaliser
     * un démarrage qui n'a pas eu lieu.
     *
     * @return array{0: SeanceRecording, 1: bool}
     */
    private function openRecording(Seance $seance): array
    {
        try {
            $recording = SeanceRecording::query()->create([
                'seance_id' => $seance->id,
                'institution_id' => $seance->institution_id,
                'status' => SeanceRecordingStatus::Recording,
                'started_at' => now(),
            ]);

            return [$recording, true];
        } catch (UniqueConstraintViolationException $exception) {
            $recording = $this->activeRecording($seance);

            if ($recording === null) {
                throw $exception;
            }

            return [$recording, false];
        }
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function stop(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canControl($seance, $user)) {
            return $this->fail(403, 'Acces reserve a l enseignant proprietaire');
        }

        $recording = $this->latestRecording($seance);
        if ($recording === null) {
            return $this->ok($this->stateCache->idleState($seance));
        }

        if ($recording->status === SeanceRecordingStatus::Recording) {
            $recording->update([
                'status' => SeanceRecordingStatus::Processing,
                'stopped_at' => now(),
            ]);
            $this->auditRecording('visio_recording_stop', $seance, $recording->refresh());
        }

        $this->stateCache->remember($seance, $recording->refresh());

        return $this->ok($recording->toRecordingPayload());
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function status(int $seanceId, User $user): array
    {
        $seance = $this->resolveSeance($seanceId);
        if ($seance === null) {
            return $this->fail(404, 'Seance non trouvee');
        }

        if (! $this->access->canRead($seance, $user)) {
            return $this->fail(403, 'Acces non autorise a cette seance');
        }

        $recording = $this->latestRecording($seance);
        if ($recording !== null) {
            $this->auditRecording('visio_recording_read', $seance, $recording);
        }

        return $this->ok($this->stateCache->stateFor($seance, $recording));
    }

    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::query()->find($seanceId)
            ?? Seance::query()->where('klassci_seance_id', $seanceId)->first();
    }

    private function latestRecording(Seance $seance): ?SeanceRecording
    {
        return SeanceRecording::query()
            ->where('seance_id', $seance->id)
            ->latest('id')
            ->first();
    }

    private function activeRecording(Seance $seance): ?SeanceRecording
    {
        return SeanceRecording::query()
            ->where('active_lock_key', SeanceRecording::activeLockKeyForSeance($seance->id))
            ->first();
    }

    private function auditRecording(string $action, Seance $seance, SeanceRecording $recording): void
    {
        $this->audit->logSecurityEvent($action, $recording, [
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'status' => $recording->status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $recording
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function ok(array $recording): array
    {
        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => ['recording' => $recording],
            ],
        ];
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function fail(int $status, string $message): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
