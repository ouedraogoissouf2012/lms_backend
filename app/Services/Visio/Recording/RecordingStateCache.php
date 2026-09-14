<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache et mise en forme de l'état d'enregistrement d'une séance.
 *
 * Extrait de {@see SeanceRecordingControlService} (#706), qui portait deux
 * responsabilités : **piloter** l'enregistrement (démarrer, arrêter, autoriser)
 * et **présenter** son état (clé de cache, repli sur la dernière valeur connue,
 * forme du payload). Le service était à 310 lignes, au-dessus de la limite de
 * 300 (`PRODUCTION_STANDARDS.md` §1.1, sans exception) ; la découpe suit la
 * consigne du garde-fou — un collaborateur injecté, pas un fichier plus gros.
 *
 * Aucun changement de comportement : les méthodes sont déplacées telles quelles.
 */
final class RecordingStateCache
{
    private const TTL_SECONDS = 1_209_600; // 14 jours

    private const CONSENT_MESSAGE = 'Cette seance peut etre enregistree. En restant dans la visio, vous acceptez que votre participation soit captee selon les regles de votre etablissement.';

    public function __construct(private readonly CacheRepository $cache) {}

    public function remember(Seance $seance, SeanceRecording $recording): void
    {
        $this->cache->put($this->key($seance), $recording->toRecordingPayload(), self::TTL_SECONDS);
    }

    /**
     * État courant : la ligne en base fait foi ; à défaut on retombe sur la
     * dernière valeur mise en cache ; à défaut sur l'état inactif.
     *
     * @return array<string, mixed>
     */
    public function stateFor(Seance $seance, ?SeanceRecording $recording): array
    {
        $cached = $this->cache->get($this->key($seance));

        if ($recording !== null) {
            $this->remember($seance, $recording);

            return $recording->toRecordingPayload();
        }

        if (is_array($cached)) {
            return $this->normalize($seance, $cached);
        }

        return $this->idleState($seance);
    }

    /**
     * @return array<string, mixed>
     */
    public function idleState(Seance $seance): array
    {
        return [
            'id' => $this->recordingId($seance),
            'status' => 'idle',
            'url' => null,
            'started_at' => null,
            'stopped_at' => null,
            'processed_at' => null,
            'error_message' => null,
            'is_recording' => false,
            'consent_required' => false,
            'consent_message' => self::CONSENT_MESSAGE,
            'retention_days' => 365,
            'can_download' => false,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(Seance $seance, array $state): array
    {
        return [
            'id' => $this->stringValue($state['id'] ?? null, $this->recordingId($seance)),
            'status' => $this->stringValue($state['status'] ?? null, 'idle'),
            'url' => $this->stringOrNull($state['url'] ?? null),
            'started_at' => $this->stringOrNull($state['started_at'] ?? null),
            'stopped_at' => $this->stringOrNull($state['stopped_at'] ?? null),
            'processed_at' => $this->stringOrNull($state['processed_at'] ?? null),
            'error_message' => $this->stringOrNull($state['error_message'] ?? null),
            'is_recording' => ($state['status'] ?? null) === 'recording',
            'consent_required' => in_array($state['status'] ?? null, SeanceRecordingStatus::activeValues(), true),
            'consent_message' => self::CONSENT_MESSAGE,
            'retention_days' => 365,
            'can_download' => false,
        ];
    }

    private function key(Seance $seance): string
    {
        return 'visio:recording:seance:'.$seance->id;
    }

    private function recordingId(Seance $seance): string
    {
        return 'seance-'.$seance->id.'-recording';
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
